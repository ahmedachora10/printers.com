<?php

namespace App\Actions\ProductInvoice;

use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\ServiceInvoice\Concerns\ReversesServiceInvoiceAccruals;
use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\StockMovementTypeEnum;
use App\Models\Product;
use App\Models\ProductInvoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * يعدّل فاتورة منتجات قائمة — آجلةً أو مدفوعة — في مكانها، برقمها نفسه. الحارس
 * (لا ملغاة ولا مرتجعة ولا مرتجع جزئي ولا دفعة مندوب) في ProductInvoicePolicy::update.
 *
 * يُفكّ أثر الفاتورة الحالية أولاً (النقاط المستبدلة تُردّ، والمكتسبة تُسحب،
 * والكوبون يُحرَّر)، ثم يُعاد تسعيرها كاملةً بحساب الإنشاء نفسه، ثم يُعاد تطبيق
 * الأثر. والحالة لا تتغيّر بالتعديل.
 *
 *  - المخزون: بالفرق وحده لكل منتج — زيادةٌ صرفُ مبيعات، ونقصٌ إرجاع — فتعديلٌ لا
 *    يمسّ الكميات لا يكتب حركة، ودفتر المخزون يبقى للإضافة فقط.
 *  - التحصيل يتبع الإجمالي الجديد: المسدَّدة عند البيع بلا صفوف دفعات محصَّلُها
 *    إجماليُّها من تلقاء نفسه، والمسدَّدة بدفعات تُكتب لها دفعة تسوية بالفرق
 *    (موجبة أو سالبة) ولا تُمسّ دفعاتها السابقة.
 */
class UpdateProductInvoiceAction
{
    use ReversesServiceInvoiceAccruals;

    public function __construct(
        private readonly CreateProductInvoiceAction $creator,
        private readonly RecordStockMovementAction $recordStockMovement,
        private readonly RedeemLoyaltyPointsAction $redeemLoyaltyPoints,
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(ProductInvoice $invoice, array $data, User $actor, ?UploadedFile $receipt = null): ProductInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor, $receipt) {
            $invoice = ProductInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $heldQty = $this->quantitiesByProduct(
                $invoice->lines()->whereNotNull('product_id')->get(['product_id', 'qty'])
                    ->map(fn ($line) => ['product_id' => (int) $line->product_id, 'qty' => (float) $line->qty])
                    ->all(),
            );

            $this->restoreRedeemedPoints($invoice);
            $this->clawBackEarnedPoints($invoice);
            $this->releaseCoupon($invoice);
            $invoice->lines()->delete();

            $calc = $this->creator->calculate($data, (int) $invoice->branch_id, (float) $invoice->vat_pct, $invoice, $heldQty);
            $total = (float) $calc['attributes']['total_amount'];

            $status = $invoice->status;
            $paidAt = $invoice->paid_at;
            $hasPayments = $invoice->payments()->exists();
            $collected = round((float) $invoice->payments()->sum('amount'), 2);

            if ($hasPayments && $status !== InvoiceStatusEnum::PAID) {
                if ($total < $collected) {
                    throw ValidationException::withMessages([
                        'lines' => 'الإجمالي الجديد أقل مما حُصِّل من الفاتورة ('.number_format($collected, 2).' ر.س).',
                    ]);
                }

                // التعديل أنزل الإجمالي إلى ما حُصِّل بالضبط: اكتمل السداد.
                if ($total === $collected) {
                    $status = InvoiceStatusEnum::PAID;
                    $paidAt = now();
                }
            }

            $invoice->update([...$calc['attributes'], 'status' => $status, 'paid_at' => $paidAt]);

            if ($hasPayments && $invoice->status === InvoiceStatusEnum::PAID && round($total - $collected, 2) !== 0.0) {
                $invoice->payments()->create([
                    'branch_id' => $invoice->branch_id,
                    'payment_method_id' => $invoice->payment_method_id,
                    'amount' => round($total - $collected, 2),
                    'paid_at' => now(),
                    'recorded_by' => $actor->id,
                    'notes' => 'تسوية بعد تعديل الفاتورة',
                ]);
            }

            if ($receipt !== null) {
                $invoice->addMedia($receipt)->toMediaCollection(ProductInvoice::RECEIPT_COLLECTION);
            }

            $this->creator->writeLines($invoice, $calc['lines']);
            $this->moveStockByDifference($invoice, $heldQty, $calc['lines'], $actor);

            if ($calc['coupon']) {
                $calc['coupon']->increment('used_count');
            }

            if ($invoice->status === InvoiceStatusEnum::PAID) {
                $this->redeemLoyaltyPoints->handle($invoice);
            }

            $this->earnLoyaltyPoints->handle($invoice);

            return $invoice->refresh();
        });
    }

    /**
     * @param  array<int, float>  $heldQty
     * @param  list<array<string, mixed>>  $lines
     */
    private function moveStockByDifference(ProductInvoice $invoice, array $heldQty, array $lines, User $actor): void
    {
        $newQty = $this->quantitiesByProduct(array_map(
            fn ($line) => ['product_id' => $line['product']?->id, 'qty' => $line['qty']],
            $lines,
        ));

        foreach (array_unique([...array_keys($heldQty), ...array_keys($newQty)]) as $productId) {
            $delta = round(($newQty[$productId] ?? 0) - ($heldQty[$productId] ?? 0), 2);

            if ($delta === 0.0) {
                continue;
            }

            $product = Product::withTrashed()->findOrFail($productId);

            $this->recordStockMovement->handle(
                $product,
                $delta > 0 ? StockMovementTypeEnum::SALE_OUT : StockMovementTypeEnum::RETURN_IN,
                abs($delta),
                [
                    'unit_cost' => $product->cost_price,
                    'reference_id' => $invoice->id,
                    'reference_type' => ProductInvoice::class,
                    'created_by' => $actor->id,
                    'notes' => "تعديل الفاتورة {$invoice->invoice_number}",
                ],
            );
        }
    }

    /**
     * @param  list<array{product_id: ?int, qty: float}>  $lines
     * @return array<int, float>
     */
    private function quantitiesByProduct(array $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            if ($line['product_id'] !== null) {
                $totals[$line['product_id']] = round(($totals[$line['product_id']] ?? 0) + $line['qty'], 2);
            }
        }

        return $totals;
    }
}
