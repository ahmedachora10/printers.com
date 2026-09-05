<?php

namespace App\Actions\Refund;

use App\Actions\Coupon\ReleaseCouponCapacity;
use App\Actions\Loyalty\ReverseLoyaltyForRefundAction;
use App\Actions\ServiceInvoice\ConsumeServiceMaterialsAction;
use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\CommissionSourceTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Enums\StockMovementTypeEnum;
use App\Models\CommissionLedger;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a refund against a product or service invoice. All side effects are
 * applied atomically:
 *  - product refunds may return stock to inventory (return_in movements), and
 *    service refunds may likewise return the materials their services drew out
 *    of it — كلاهما اختياري ومرة واحدة لكل فاتورة;
 *  - service refunds reverse the employee's *unpaid* commission proportionally
 *    by inserting negative offsetting rows in the immutable commission ledger;
 *  - both types unwind the invoice's loyalty effect by the same refunded
 *    fraction (earned points clawed back, redeemed points returned, cumulative
 *    spend rolled back);
 *  - and when the refund exhausts everything that was collected on the invoice,
 *    the invoice itself is sealed as RETURNED and its coupon capacity released.
 *
 * سقفُ المرتجع هو ما حُصِّل من الفاتورة لا إجماليُّها: لا يُردُّ نقداً مالٌ لم
 * يُقبض. والحالة لا تنقلب إلا عند استنفاد ذلك السقف — المرتجع الجزئي يترك
 * الفاتورة قائمةً محتسبةً في المبيعات، وصفُّ مرتجعه وحده هو ما يُطرح منها في
 * التقارير. لو قلبناها بأوّل مرتجع جزئي لسقطت الفاتورة كلُّها من الإيراد
 * (InvoiceStatusEnum::excludedFromRevenue) ولسقط معها صفُّ مرتجعها من الخصم
 * (DailyReportController::refundsDaily) فيضيع الفرق بينهما.
 */
class CreateRefundAction
{
    public function __construct(
        private readonly RecordStockMovementAction $recordStockMovement,
        private readonly ReverseLoyaltyForRefundAction $reverseLoyalty,
        private readonly ConsumeServiceMaterialsAction $consumeMaterials,
    ) {}

    /**
     * @param  array{source_type: string, invoice_id: int, amount: float|string, reason: string, reverse_stock?: bool}  $data
     */
    public function handle(array $data, User $actor): Refund
    {
        $type = InvoiceTypeEnum::from($data['source_type']);
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($data, $actor, $type, $amount) {
            /** @var ProductInvoice|ServiceInvoice $invoice */
            $invoice = $type->modelClass()::query()
                ->lockForUpdate()
                ->findOrFail($data['invoice_id']);

            // Branch scoping: non super-admins may only refund their own branch.
            if (! $actor->roleName->isSuperAdmin() && $invoice->branch_id !== $actor->branchId) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'هذه الفاتورة لا تخص فرعك.',
                ]);
            }

            if ($invoice->status === InvoiceStatusEnum::CANCELLED) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'لا يمكن إرجاع فاتورة ملغاة.',
                ]);
            }

            if ($invoice->status === InvoiceStatusEnum::RETURNED) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'الفاتورة مُرتجعة بالفعل.',
                ]);
            }

            $alreadyRefunded = (float) Refund::query()
                ->where('invoice_type', $type->modelClass())
                ->where('invoice_id', $invoice->id)
                ->sum('amount');

            $invoiceTotal = (float) $invoice->total_amount;

            // ما حُصِّل فعلاً هو سقف ما يُردّ — لا إجماليُّ الفاتورة. الفاتورة
            // المسدَّدة عند البيع محصَّلها إجماليُّها، والمدفوعة جزئياً مجموعُ
            // دفعاتها، والآجلة صفر: ردُّ نقدٍ لم يُقبض كان يُنقص المحصَّل في
            // التقرير اليومي بمالٍ لم يدخل الصندوق أصلاً.
            $collected = $invoice->paidAmount();

            if ($collected <= 0) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'لم يُحصَّل من هذه الفاتورة شيء، فلا مبلغ يُردّ. تُسترجع الفاتورة الآجلة من شاشة الفواتير.',
                ]);
            }

            if (round($alreadyRefunded + $amount, 2) > round($collected, 2)) {
                $remaining = round(max($collected - $alreadyRefunded, 0), 2);

                throw ValidationException::withMessages([
                    'amount' => "مبلغ المرتجع يتجاوز ما حُصِّل من الفاتورة ({$remaining} ر.س قابلة للإرجاع).",
                ]);
            }

            // عكسُ المخزون اختياري في النوعين: فاتورةُ المنتجات تعيد بضاعتها،
            // وفاتورةُ الخدمات تعيد خاماتها. والإرجاع كامل لا بالنسبة — المرتجع
            // الجزئي مبلغٌ لا مادة، ونصفُ بنرٍ مطبوع لا يعود نصفَ مترٍ من الفينيل.
            $reverseStock = (bool) ($data['reverse_stock'] ?? false);

            if ($reverseStock) {
                $stockAlreadyReversed = Refund::query()
                    ->where('invoice_type', $type->modelClass())
                    ->where('invoice_id', $invoice->id)
                    ->where('stock_reversed', true)
                    ->exists();

                if ($stockAlreadyReversed) {
                    throw ValidationException::withMessages([
                        'reverse_stock' => 'تم إرجاع مخزون هذه الفاتورة مسبقاً.',
                    ]);
                }
            }

            $refund = Refund::create([
                'branch_id' => $invoice->branch_id,
                'user_id' => $actor->id,
                'source_type' => $type,
                'invoice_id' => $invoice->id,
                'invoice_type' => $type->modelClass(),
                'amount' => $amount,
                'reason' => $data['reason'],
                'stock_reversed' => $reverseStock,
            ]);

            if ($reverseStock) {
                if ($invoice instanceof ServiceInvoice) {
                    // فاتورةٌ لم تُعتمد لم تُخصم خاماتها أصلاً، وفاتورةٌ سبق أن
                    // أُعيدت خاماتها لا تُعاد مرتين — الدالة تكتشف الحالتين من
                    // حركات الفاتورة نفسها، فلا حارس إضافي هنا.
                    $this->consumeMaterials->restore($invoice, (int) $actor->id);
                } else {
                    $this->reverseStock($invoice, $refund, $actor);
                }
            }

            if ($type === InvoiceTypeEnum::SERVICE) {
                $this->reverseCommission($invoice, $amount, $invoiceTotal);
            }

            // النقاط والإنفاق التراكمي يُفكّان بعد كتابة صفّ المرتجع، لأن الحساب
            // يقوم على مجموع ما استُرجع على الفاتورة شاملاً هذه الدفعة.
            $this->reverseLoyalty->handle($invoice, $amount);

            // استُرجع كل ما حُصِّل: الفاتورة مرتجعة. تسقط من الإيراد ومن العمولة
            // المستحقة (ExcludeReturnedCommission)، ولا مطالبة على العميل
            // بباقيها، وتُردّ سعة كوبونها. المرتجع الجزئي لا يبلغ هذا الحدّ
            // فتبقى حالته كما هي.
            if (round($alreadyRefunded + $amount, 2) >= round($collected, 2)) {
                ReleaseCouponCapacity::apply($invoice);
                $invoice->update(['status' => InvoiceStatusEnum::RETURNED]);
            }

            return $refund;
        });
    }

    /**
     * Return each linked product line's quantity to inventory.
     */
    private function reverseStock(ProductInvoice $invoice, Refund $refund, User $actor): void
    {
        $invoice->loadMissing('lines.product');

        foreach ($invoice->lines as $line) {
            if (! $line->product) {
                continue;
            }

            $this->recordStockMovement->handle(
                $line->product,
                StockMovementTypeEnum::RETURN_IN,
                (float) $line->qty,
                [
                    'unit_cost' => $line->product->cost_price,
                    'reference_id' => $refund->id,
                    'reference_type' => Refund::class,
                    'created_by' => $actor->id,
                    'notes' => "إرجاع فاتورة {$invoice->invoice_number}",
                ],
            );
        }
    }

    /**
     * Reverse the unpaid commission tied to the invoice's service lines,
     * scaled by the refunded fraction of the invoice total. Each reversal is a
     * new negative row — the ledger is never mutated. Paid commission is left
     * untouched (clawing back settled payments is out of scope).
     */
    private function reverseCommission(ServiceInvoice $invoice, float $amount, float $invoiceTotal): void
    {
        if ($invoiceTotal <= 0) {
            return;
        }

        $fraction = min(1.0, $amount / $invoiceTotal);

        $lineIds = $invoice->lines()->pluck('id');

        if ($lineIds->isEmpty()) {
            return;
        }

        $entries = CommissionLedger::query()
            ->where('invoice_line_type', ServiceInvoiceLine::class)
            ->whereIn('invoice_line_id', $lineIds)
            ->whereNull('paid_at')
            ->where('amount', '>', 0)
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            $reversal = round((float) $entry->amount * $fraction, 2);

            if ($reversal <= 0) {
                continue;
            }

            CommissionLedger::create([
                'user_id' => $entry->user_id,
                'branch_id' => $entry->branch_id,
                'invoice_line_id' => $entry->invoice_line_id,
                'invoice_line_type' => $entry->invoice_line_type,
                'amount' => -$reversal,
                'is_tahazir' => $entry->is_tahazir,
                'tier_applied' => $entry->tier_applied,
                'source_type' => CommissionSourceTypeEnum::STANDARD,
                'earned_at' => now(),
            ]);
        }
    }
}
