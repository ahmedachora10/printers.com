<?php

namespace App\Actions\InvoicePayment;

use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\ServiceInvoice\MarkServiceInvoicePaidAction;
use App\Enums\InvoiceStatusEnum;
use App\Models\InvoicePayment;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * يسجّل دفعة على فاتورة — عربوناً كانت أو دفعة لاحقة — ويحرّك حالة الفاتورة تبعاً
 * لما تجمّع من تحصيل:
 *
 *  - مجموع الدفعات < الإجمالي  ← «مدفوعة جزئياً»
 *  - مجموع الدفعات = الإجمالي  ← «مدفوعة»، وتاريخ السداد لحظةُ آخر دفعة، ثم تُسلك
 *    مسارات الاعتماد المعتادة (عمولة الموظف في commission_ledger، ونقاط الولاء:
 *    خصمُ ما استبدله العميل واحتسابُ ما اكتسبه).
 *
 * لا تُسجَّل دفعة على فاتورة مسدَّدة أو ملغاة أو مرتجعة، فلا يُعاد اعتمادُ فاتورة
 * سبق اعتمادها ولا تُكتب عمولتها أو نقاطها مرتين. إشعار الموظف بالاعتماد يرسله
 * المتحكِّم من خارج المعاملة عند اكتمال السداد بهذه الدفعة وحدها.
 *
 * جدول الدفعات للإضافة فقط: تصحيح دفعة خاطئة يمرّ من هنا بمبلغ سالب مقابل، على
 * أن يبقى مجموع الدفعات بين الصفر وإجمالي الفاتورة.
 */
class RecordInvoicePaymentAction
{
    public function __construct(
        private readonly MarkServiceInvoicePaidAction $markServiceInvoicePaid,
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
        private readonly RedeemLoyaltyPointsAction $redeemLoyaltyPoints,
    ) {}

    /**
     * @param  array{amount: float|string, paid_at?: string|null, payment_method_id?: int|null, notes?: string|null}  $data
     * @param  UploadedFile|null  $receipt  إيصال التحويل حين تشترطه طريقة الدفع
     * @param  bool  $confirmedShortage  إقرارُ المعتمِد بعجز خامات المخزون حين تُغلق هذه الدفعةُ الفاتورة
     */
    public function handle(ProductInvoice|ServiceInvoice $invoice, array $data, User $actor, ?UploadedFile $receipt = null, bool $confirmedShortage = false): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $actor, $receipt, $confirmedShortage) {
            // قفل صف الفاتورة يُسلسل الدفعات المتزامنة، فلا يمرّ تحصيلان معاً
            // فيتجاوز مجموعُهما الإجمالي.
            $invoice = $invoice->newQuery()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->status->acceptsPayment()) {
                throw ValidationException::withMessages([
                    'amount' => $invoice->status === InvoiceStatusEnum::PAID
                        ? 'الفاتورة مسدَّدة بالكامل — لا يمكن تسجيل دفعة جديدة عليها.'
                        : 'لا يمكن تسجيل دفعة على فاتورة ملغاة أو مرتجعة.',
                ]);
            }

            $amount = round((float) $data['amount'], 2);
            $total = round((float) $invoice->total_amount, 2);
            $collected = round((float) $invoice->payments()->sum('amount'), 2);
            $remaining = round($total - $collected, 2);

            if ($amount === 0.0) {
                throw ValidationException::withMessages(['amount' => 'أدخل مبلغ الدفعة.']);
            }

            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'المبلغ يتجاوز المتبقي على الفاتورة. المتبقي: '.number_format($remaining, 2).' ر.س.',
                ]);
            }

            // دفعة تصحيحية سالبة لا يجوز أن تهبط بالمحصَّل تحت الصفر.
            if (round($collected + $amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'لا يمكن أن يقل مجموع الدفعات عن صفر. المحصَّل حالياً: '.number_format($collected, 2).' ر.س.',
                ]);
            }

            $paidAt = isset($data['paid_at']) && $data['paid_at'] !== null
                ? Date::parse($data['paid_at'])
                : now();

            $payment = $invoice->payments()->create([
                'branch_id' => $invoice->branch_id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'recorded_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($receipt !== null) {
                $payment->addMedia($receipt)->toMediaCollection(InvoicePayment::RECEIPT_COLLECTION);
            }

            if (round($collected + $amount, 2) >= $total) {
                $this->settle($invoice, $paidAt, $confirmedShortage);
            } else {
                $invoice->update(['status' => InvoiceStatusEnum::PARTIALLY_PAID]);
            }

            return $payment;
        });
    }

    /**
     * اكتمل السداد: تُسلك مسارات الاعتماد نفسها التي يسلكها المحاسب حين يعتمد
     * الفاتورة كاملة. فواتير الخدمات تمرّ من MarkServiceInvoicePaidAction (عمولة
     * + نقاط)، وفواتير المنتجات لا عمولة لها فيبقى منها احتساب النقاط.
     */
    private function settle(ProductInvoice|ServiceInvoice $invoice, CarbonInterface $paidAt, bool $confirmedShortage = false): void
    {
        if ($invoice instanceof ServiceInvoice) {
            $this->markServiceInvoicePaid->handle($invoice, $paidAt, $confirmedShortage);

            return;
        }

        $invoice->update([
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => $paidAt,
        ]);

        // خصم النقاط المحجوزة ثم احتساب المكتسبة، بالترتيب نفسه الذي يسلكه
        // اعتماد فاتورة الخدمة.
        $this->redeemLoyaltyPoints->handle($invoice);
        $this->earnLoyaltyPoints->handle($invoice);
    }
}
