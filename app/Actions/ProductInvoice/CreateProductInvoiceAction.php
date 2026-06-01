<?php

namespace App\Actions\ProductInvoice;

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductInvoiceAction
{
    public function __construct(
        private readonly RecordStockMovementAction $recordStockMovement,
    ) {}

    public function handle(array $data): ProductInvoice
    {
        $user = auth()->user();
        $branchId = $user->branchId;
        $branch = Branch::findOrFail($branchId);
        $vatPct = (float) $branch->vat_rate_override;

        return DB::transaction(function () use ($data, $user, $branchId, $vatPct) {
            // Lock the branch's products that are being sold to keep stock checks
            // and the sale movements consistent under concurrent sales.
            $productIds = collect($data['lines'])->pluck('product_id')->unique();
            $products = Product::query()
                ->where('branch_id', $branchId)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = [];
            $subtotal = 0.0;

            foreach ($data['lines'] as $line) {
                $product = $products->get($line['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'lines' => 'أحد المنتجات غير موجود في هذا الفرع.',
                    ]);
                }

                $qty = (int) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                $discountPct = (float) ($line['discount_pct'] ?? 0);

                if ($qty > $product->current_stock) {
                    throw ValidationException::withMessages([
                        'lines' => "الكمية المطلوبة من \"{$product->name}\" تتجاوز المخزون المتاح ({$product->current_stock}).",
                    ]);
                }

                $lineSubtotal = round($qty * $unitPrice * (1 - $discountPct / 100), 2);
                $subtotal += $lineSubtotal;

                $lines[] = [
                    'product' => $product,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_pct' => $discountPct,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            $vatAmount = round($subtotal * $vatPct / 100, 2);
            $total = round($subtotal + $vatAmount, 2);
            $status = InvoiceStatusEnum::from($data['status']);

            $invoice = ProductInvoice::create([
                'invoice_number' => $this->generateInvoiceNumber($branchId),
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'customer_id' => $data['customer_id'] ?? null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'subtotal' => $subtotal,
                'vat_pct' => $vatPct,
                'vat_amount' => $vatAmount,
                'total_amount' => $total,
                'status' => $status,
                'paid_at' => $status === InvoiceStatusEnum::PAID ? now() : null,
            ]);

            foreach ($lines as $line) {
                $product = $line['product'];

                $invoice->lines()->create([
                    'product_id' => $product->id,
                    'product_name' => $line['product_name'],
                    'sku' => $line['sku'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => $line['discount_pct'],
                    'subtotal' => $line['subtotal'],
                ]);

                $this->recordStockMovement->handle(
                    $product,
                    StockMovementTypeEnum::SALE_OUT,
                    $line['qty'],
                    [
                        'unit_cost' => $product->cost_price,
                        'reference_id' => $invoice->id,
                        'reference_type' => ProductInvoice::class,
                        'created_by' => $user->id,
                    ],
                );
            }

            return $invoice;
        });
    }

    private function generateInvoiceNumber(int $branchId): string
    {
        $seq = ProductInvoice::withTrashed()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('INV-%03d-%05d', $branchId, $seq);
    }
}
