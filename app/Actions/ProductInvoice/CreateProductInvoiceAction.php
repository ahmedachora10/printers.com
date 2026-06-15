<?php

namespace App\Actions\ProductInvoice;

use App\Actions\Agent\ResolveInvoiceAgentAction;
use App\Actions\Loyalty\ApplyLoyaltyDiscountsAction;
use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\AgentDiscountModeEnum;
use App\Enums\CouponDiscountTypeEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\Product;
use App\Models\ProductInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductInvoiceAction
{
    public function __construct(
        private readonly RecordStockMovementAction $recordStockMovement,
        private readonly ResolveInvoiceAgentAction $resolveAgent,
        private readonly ApplyLoyaltyDiscountsAction $loyalty,
        private readonly RedeemLoyaltyPointsAction $redeemLoyaltyPoints,
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
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

            $customer = $customerId !== null ? Customer::find($customerId) : null;
            $config = LoyaltyConfig::forBranch($branchId);

            [$agentId, $agentMode, $agentRate] = $this->resolveAgent->handle(
                isset($data['agent_id']) ? (int) $data['agent_id'] : null,
                $branchId,
            );

            // Loyalty benefits (tier discount, redemption) apply only to
            // eligible customers: individual, not agent-linked, on a non-agent
            // invoice — B2B sales are settled via agent terms instead.
            $loyaltyEligible = $agentId === null
                && $customer !== null
                && $customer->customer_type === CustomerTypeEnum::Individual
                && $customer->agent_id === null;

            // Discount pipeline: subtotal → tier → coupon → agent → points → VAT.
            [$tierPct, $tierDiscount] = $this->loyalty->tierDiscount($customer, $loyaltyEligible, $config, $subtotal);
            $afterTier = round($subtotal - $tierDiscount, 2);

            [$coupon, $couponDiscount] = $this->resolveCoupon($data, $branchId, $afterTier);
            $afterCoupon = round($afterTier - $couponDiscount, 2);

            // discount mode reduces the taxable base; rebate is recorded on the
            // invoice after the total but never deducted from it.
            $agentDiscount = $agentMode === AgentDiscountModeEnum::Discount
                ? round($afterCoupon * $agentRate / 100, 2)
                : 0.0;
            $afterAgent = round($afterCoupon - $agentDiscount, 2);

            $requestedPoints = (int) ($data['redeem_points'] ?? 0);
            [$pointsRedeemed, $pointsDiscount] = $this->loyalty->redemption($customer, $loyaltyEligible, $config, $requestedPoints, $afterAgent);

            $taxableBase = round($afterAgent - $pointsDiscount, 2);
            $vatAmount = round($taxableBase * $vatPct / 100, 2);
            $total = round($taxableBase + $vatAmount, 2);

            $agentRebate = $agentMode === AgentDiscountModeEnum::Rebate
                ? round($total * $agentRate / 100, 2)
                : 0.0;

            $status = InvoiceStatusEnum::from($data['status']);

            $invoice = ProductInvoice::create([
                'invoice_number' => $this->generateInvoiceNumber($branchId),
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'agent_id' => $agentId,
                'coupon_id' => $coupon?->id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'subtotal' => $subtotal,
                'tier_discount_pct' => $tierPct,
                'tier_discount_amount' => $tierDiscount,
                'coupon_discount' => $couponDiscount,
                'agent_discount' => $agentDiscount,
                'agent_rebate' => $agentRebate,
                'points_redeemed' => $pointsRedeemed,
                'points_discount' => $pointsDiscount,
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

            // Spend redeemed points (decrement + immutable ledger row), then
            // accrue earnings. Earning re-reads the post-redemption balance and
            // no-ops unless the invoice is paid for an eligible customer.
            if ($pointsRedeemed > 0 && $customerId !== null) {
                $this->redeemLoyaltyPoints->handle($invoice, $customerId, $pointsRedeemed);
            }

            $this->earnLoyaltyPoints->handle($invoice);

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
    private function resolveCoupon(array $data, int $branchId, float $base): array
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
            ? $base * (float) $coupon->discount_value / 100
            : (float) $coupon->discount_value;

        return [$coupon, round(min($discount, $base), 2)];
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
