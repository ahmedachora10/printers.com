<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * يفكّ أثر الولاء عن فاتورة استُرجع جزءٌ من قيمتها أو كلّها، بنسبة ما استُرجع من
 * إجماليها — على المنوال نفسه الذي تُعكس به العمولة في CreateRefundAction:
 *
 *  - النقاط المكتسبة تُسحب من الرصيد، ولا يهبط الرصيد تحت الصفر؛
 *  - النقاط التي استبدلها العميل على الفاتورة تُعاد إليه، فالمبلغ المسترجَع نقديٌّ
 *    ولا يشمل ما دفعه نقاطاً؛
 *  - الإنفاق التراكمي يُخصم منه المبلغ المسترجَع، والفئة لا تنزل أبداً (قاعدة عمل:
 *    الفئات تُرقّى ولا تُنزَّل إلا بتدخل يدوي).
 *
 * الحساب يقوم على مجموع ما استُرجع على الفاتورة حتى الآن — لا على الدفعة الواحدة —
 * ثم يطرح منه ما سبق تطبيقه فعلاً. فمرتجعان جزئيان يبلغان بالضبط ما يبلغه مرتجعٌ
 * كامل، ولا يُسحب شيء مرتين. وهذا نفسه ما يجعل مرتجعاً على فاتورة سبق ردّها عبر
 * ReversesServiceInvoiceAccruals لا يكرّر السحب: ما ردّه مسارُ الإرجاع محسوبٌ هنا
 * ضمن «ما سبق تطبيقه».
 *
 * السجل غير قابل للتعديل: كل عكس صفٌّ جديد من نوع «تعديل يدوي».
 */
class ReverseLoyaltyForRefundAction
{
    /**
     * @param  float  $refundAmount  مبلغ هذه الدفعة من المرتجع، بعد كتابة صفّها
     */
    public function handle(ProductInvoice|ServiceInvoice $invoice, float $refundAmount): void
    {
        $invoiceTotal = (float) $invoice->total_amount;

        if ($invoice->customer_id === null || $invoiceTotal <= 0) {
            return;
        }

        // صفّ هذا المرتجع مكتوبٌ سلفاً، فالمجموع يشمله.
        $refundedToDate = (float) Refund::query()
            ->where('invoice_type', $invoice->getMorphClass())
            ->where('invoice_id', $invoice->id)
            ->sum('amount');

        $fraction = min(1.0, $refundedToDate / $invoiceTotal);

        $earned = $this->pointsOfType($invoice, LoyaltyTransactionTypeEnum::Earn);
        $redeemed = -$this->pointsOfType($invoice, LoyaltyTransactionTypeEnum::Redeem);
        [$alreadyClawed, $alreadyRestored] = $this->adjustmentsSoFar($invoice);

        $toClaw = max(0, (int) floor($earned * $fraction) - $alreadyClawed);
        $toRestore = max(0, (int) floor($redeemed * $fraction) - $alreadyRestored);

        // الإنفاق التراكمي لا يُرفع إلا في الكتلة نفسها التي تكتب صفّ الاكتساب،
        // فوجود اكتسابٍ على الفاتورة هو بعينه شرطُ صحّة الخصم منه. والمبلغ
        // المسترجَع إجماليٌّ شامل الضريبة، وهو المقياس الذي أُضيف به الإنفاق،
        // فيُخصم كما هو بلا اقتطاع الضريبة منه.
        $spendRollback = $earned > 0 ? round($refundAmount, 2) : 0.0;

        if ($toClaw === 0 && $toRestore === 0 && $spendRollback <= 0) {
            return;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()
            ->whereKey($invoice->customer_id)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            return;
        }

        $balance = (int) $customer->points_balance;

        // السحب أولاً ثم الاسترجاع، ليأتي balance_after في الصفّين متسلسلاً.
        // يُقيَّد السحب بالرصيد المتاح: نقاطٌ أُنفقت لا تُستردّ بجعل الرصيد سالباً.
        $removed = min($toClaw, $balance);

        if ($removed > 0) {
            $balance -= $removed;
            $this->record($invoice, $customer, -$removed, $balance, "سحب نقاط مكتسبة بعد مرتجع الفاتورة {$invoice->invoice_number}");
        }

        if ($toRestore > 0) {
            $balance += $toRestore;
            $this->record($invoice, $customer, $toRestore, $balance, "استرجاع نقاط مستبدلة بعد مرتجع الفاتورة {$invoice->invoice_number}");
        }

        // الفئة تتبع الإنفاق هبوطاً كما تتبعه صعوداً، فمرتجعٌ يهبط بالإنفاق دون
        // حدّ الفئة يُنزل صاحبه عنها.
        $newSpend = max(0, (float) $customer->cumulative_spend - $spendRollback);

        $customer->update([
            'points_balance' => $balance,
            'cumulative_spend' => $newSpend,
            'tier' => LoyaltyConfig::forBranch($customer->branch_id)->tierForSpend($newSpend),
        ]);
    }

    private function pointsOfType(ProductInvoice|ServiceInvoice $invoice, LoyaltyTransactionTypeEnum $type): int
    {
        return (int) $this->transactions($invoice)->where('type', $type)->sum('points');
    }

    /**
     * ما سبق عكسه على هذه الفاتورة، مفروزاً بالإشارة: السالب سحبٌ لنقاط مكتسبة،
     * والموجب ردٌّ لنقاط مستبدلة.
     *
     * @return array{0: int, 1: int} [clawedBack, restored]
     */
    private function adjustmentsSoFar(ProductInvoice|ServiceInvoice $invoice): array
    {
        $adjustments = $this->transactions($invoice)
            ->where('type', LoyaltyTransactionTypeEnum::ManualAdjust);

        return [
            -(int) (clone $adjustments)->where('points', '<', 0)->sum('points'),
            (int) (clone $adjustments)->where('points', '>', 0)->sum('points'),
        ];
    }

    /** @return Builder<LoyaltyTransaction> */
    private function transactions(ProductInvoice|ServiceInvoice $invoice): Builder
    {
        return LoyaltyTransaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('invoice_type', $invoice->getMorphClass());
    }

    private function record(ProductInvoice|ServiceInvoice $invoice, Customer $customer, int $points, int $balanceAfter, string $notes): void
    {
        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->getMorphClass(),
            'type' => LoyaltyTransactionTypeEnum::ManualAdjust,
            'points' => $points,
            'balance_after' => $balanceAfter,
            'notes' => $notes,
        ]);
    }
}
