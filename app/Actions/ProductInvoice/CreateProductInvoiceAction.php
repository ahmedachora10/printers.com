<?php

namespace App\Actions\ProductInvoice;

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\CouponDiscountTypeEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductInvoiceAction
{
    public function __construct(
        private readonly RecordStockMovementAction $recordStockMovement,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): ProductInvoice
    {
        $user = auth()->user();
        $branchId = $user->branchId;
        $branch = Branch::findOrFail($branchId);
        $vatPct = (float) $branch->vat_rate_override;

        return DB::transaction(function () use ($data, $user, $branchId, $vatPct) {
            $customerId = $this->resolveCustomerId($data, $branchId);

            // Lock the branch's products that are being sold to keep stock checks
            // and the sale movements consistent under concurrent sales.
            $productIds = collect((array) $data['lines'])
                ->pluck('product_id')
                ->filter()
                ->unique();

            $products = $productIds->isNotEmpty()
                ? Product::query()
                    ->where('branch_id', $branchId)
                    ->whereIn('id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id')
                : collect();

            $lines = [];
            $subtotal = 0.0;

            foreach ($data['lines'] as $line) {
                $qty = (int) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                $discountPct = (float) ($line['discount_pct'] ?? 0);
                $lineSubtotal = round($qty * $unitPrice * (1 - $discountPct / 100), 2);
                $subtotal += $lineSubtotal;

                // Manual line — no linked product, no stock movement.
                if (empty($line['product_id'])) {
                    $lines[] = [
                        'product' => null,
                        'product_name' => $line['name'],
                        'sku' => null,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'discount_pct' => $discountPct,
                        'subtotal' => $lineSubtotal,
                    ];

                    continue;
                }

                $product = $products->get($line['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'lines' => 'أحد المنتجات غير موجود في هذا الفرع.',
                    ]);
                }

                if ($qty > $product->current_stock) {
                    throw ValidationException::withMessages([
                        'lines' => "الكمية المطلوبة من \"{$product->name}\" تتجاوز المخزون المتاح ({$product->current_stock}).",
                    ]);
                }

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

            [$coupon, $couponDiscount] = $this->resolveCoupon($data, $branchId, $subtotal);

            $taxableBase = round($subtotal - $couponDiscount, 2);
            $vatAmount = round($taxableBase * $vatPct / 100, 2);
            $total = round($taxableBase + $vatAmount, 2);
            $status = InvoiceStatusEnum::from($data['status']);

            $invoice = ProductInvoice::create([
                'invoice_number' => $this->generateInvoiceNumber($branchId),
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'coupon_id' => $coupon?->id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'subtotal' => $subtotal,
                'coupon_discount' => $couponDiscount,
                'vat_pct' => $vatPct,
                'vat_amount' => $vatAmount,
                'total_amount' => $total,
                'status' => $status,
                'paid_at' => $status === InvoiceStatusEnum::PAID ? now() : null,
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'product_id' => $line['product']?->id,
                    'product_name' => $line['product_name'],
                    'sku' => $line['sku'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => $line['discount_pct'],
                    'subtotal' => $line['subtotal'],
                ]);

                if (! $line['product']) {
                    continue;
                }

                $product = $line['product'];

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

            if ($coupon) {
                $coupon->increment('used_count');
            }

            return $invoice;
        });
    }

    /**
     * Resolve the customer for the invoice: an explicit customer, a walk-in
     * (found-or-created from name/phone), or none (cash customer).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomerId(array $data, int $branchId): ?int
    {
        if (! empty($data['customer_id'])) {
            return (int) $data['customer_id'];
        }

        $name = trim((string) ($data['walkin_name'] ?? ''));
        $phone = trim((string) ($data['walkin_phone'] ?? ''));

        if ($name === '' && $phone === '') {
            return null;
        }

        if ($phone !== '') {
            $existing = Customer::query()
                ->where('branch_id', $branchId)
                ->where('phone', $phone)
                ->first();

            if ($existing) {
                return $existing->id;
            }
        }

        return Customer::create([
            'full_name' => $name !== '' ? $name : 'عميل عابر',
            'phone' => $phone !== '' ? $phone : null,
            'branch_id' => $branchId,
            'customer_type' => CustomerTypeEnum::Individual,
            'is_active' => true,
        ])->id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?Coupon, 1: float}
     */
    private function resolveCoupon(array $data, int $branchId, float $subtotal): array
    {
        $code = trim((string) ($data['coupon_code'] ?? ''));

        if ($code === '') {
            return [null, 0.0];
        }

        $coupon = Coupon::query()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->first();

        if (! $coupon
            || ! $coupon->is_active
            || ($coupon->expires_at !== null && $coupon->expires_at->isPast())
            || ($coupon->capacity !== null && $coupon->used_count >= $coupon->capacity)
        ) {
            throw ValidationException::withMessages([
                'coupon_code' => 'الكوبون غير صالح أو منتهي الصلاحية.',
            ]);
        }

        $discount = $coupon->discount_type === CouponDiscountTypeEnum::Percentage
            ? $subtotal * (float) $coupon->discount_value / 100
            : (float) $coupon->discount_value;

        return [$coupon, round(min($discount, $subtotal), 2)];
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
