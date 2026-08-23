<?php

namespace App\Actions\ProductInvoice;

use App\Actions\Agent\ResolveInvoiceAgentAction;
use App\Actions\Loyalty\ApplyLoyaltyDiscountsAction;
use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\Loyalty\ResolveAvailablePointsAction;
use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
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
use App\Support\Quantity;
use Illuminate\Http\UploadedFile;
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
        private readonly ResolveAvailablePointsAction $availablePoints,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, ?UploadedFile $receipt = null): ProductInvoice
    {
        $user = auth()->user();
        $branchId = $user->branchId;
        $branch = Branch::findOrFail($branchId);
        $vatPct = (float) $branch->vat_rate_override;

        return DB::transaction(function () use ($data, $user, $branchId, $vatPct, $receipt) {
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
                $unitPrice = (float) $line['unit_price'];
                $discountPct = (float) ($line['discount_pct'] ?? 0);

                // Manual line — no linked product, no stock movement, and no
                // dimensions: its quantity is whatever the cashier typed.
                if (empty($line['product_id'])) {
                    $qty = round((float) $line['qty'], 2);
                    $lineSubtotal = round($qty * $unitPrice * (1 - $discountPct / 100), 2);
                    $subtotal += $lineSubtotal;

                    $lines[] = [
                        'product' => null,
                        'product_name' => $line['name'],
                        'sku' => null,
                        'qty' => $qty,
                        'width_cm' => null,
                        'height_cm' => null,
                        'pieces' => null,
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

                // تاسك 51: كمية المنتج المسعّر بالمتر المربع تُشتقّ هنا من المقاس
                // وعدد القطع — لا تُؤخذ مما أرسلته الواجهة، فالسعر والمخزون معاً
                // يقومان عليها. أما منتج القطعة فكميته هي المُرسَلة كما كانت.
                [$qty, $widthCm, $heightCm, $pieces] = $this->resolveLineQuantity($line, $product);

                $lineSubtotal = round($qty * $unitPrice * (1 - $discountPct / 100), 2);
                $subtotal += $lineSubtotal;

                if ($qty > (float) $product->current_stock) {
                    $available = Quantity::format($product->current_stock);

                    throw ValidationException::withMessages([
                        'lines' => "الكمية المطلوبة من \"{$product->name}\" تتجاوز المخزون المتاح ({$available}).",
                    ]);
                }

                $lines[] = [
                    'product' => $product,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'qty' => $qty,
                    'width_cm' => $widthCm,
                    'height_cm' => $heightCm,
                    'pieces' => $pieces,
                    'unit_price' => $unitPrice,
                    'discount_pct' => $discountPct,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $subtotal = round($subtotal, 2);

            $customer = $customerId !== null ? Customer::find($customerId) : null;
            $config = LoyaltyConfig::forBranch($branchId);

            [$agentId, $agentMode, $agentType, $agentRate] = $this->resolveAgent->handle(
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
            // invoice after the total but never deducted from it. The rate is
            // read as a percentage or a flat SAR amount per the discount type.
            $agentDiscount = $agentMode === AgentDiscountModeEnum::Discount
                ? $this->agentAmount($agentType, $agentRate, $afterCoupon)
                : 0.0;
            $afterAgent = round($afterCoupon - $agentDiscount, 2);

            $requestedPoints = (int) ($data['redeem_points'] ?? 0);

            // الرصيد المتاح لا المسجَّل: ما حُجز على فواتير هذا العميل التي لم
            // تُعتمد بعد ليس له أن يُستبدل مرة أخرى.
            $available = $customer !== null ? $this->availablePoints->handle($customer) : null;

            [$pointsRedeemed, $pointsDiscount] = $this->loyalty->redemption($customer, $loyaltyEligible, $config, $requestedPoints, $afterAgent, $available);

            // الأسعار المُدخلة في نقطة البيع شاملة لضريبة القيمة المضافة: ما يبقى
            // بعد كامل سلسلة الخصومات هو ما يدفعه العميل بالضبط، والضريبة تُستخرج
            // من داخله بالطرح لا بالضرب. مطابق لفاتورة الخدمة تماماً.
            $total = round($afterAgent - $pointsDiscount, 2);
            $netBeforeVat = round($total / (1 + $vatPct / 100), 2);
            $vatAmount = round($total - $netBeforeVat, 2);

            $agentRebate = $agentMode === AgentDiscountModeEnum::Rebate
                ? $this->agentAmount($agentType, $agentRate, $netBeforeVat)
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
                // Invoice-level remark for the customer, printed under the lines.
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'status' => $status,
                'paid_at' => $status === InvoiceStatusEnum::PAID ? now() : null,
            ]);

            if ($receipt !== null) {
                $invoice->addMedia($receipt)->toMediaCollection(ProductInvoice::RECEIPT_COLLECTION);
            }

            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'product_id' => $line['product']?->id,
                    'product_name' => $line['product_name'],
                    'sku' => $line['sku'],
                    'qty' => $line['qty'],
                    'width_cm' => $line['width_cm'],
                    'height_cm' => $line['height_cm'],
                    'pieces' => $line['pieces'],
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

            // النقاط لا تُخصم من رصيد العميل إلا عند اعتماد الفاتورة: المدفوعة
            // تُخصم نقاطها الآن، والآجلة تبقى نقاطها محجوزةً عليها حتى يكتمل
            // سدادها. ثم يأتي الاكتساب، ولا يقع إلا على فاتورة مدفوعة.
            if ($status === InvoiceStatusEnum::PAID) {
                $this->redeemLoyaltyPoints->handle($invoice);
            }

            $this->earnLoyaltyPoints->handle($invoice);

            return $invoice;
        });
    }

    /**
     * A product line's billable quantity, plus the sqm snapshot that explains it.
     *
     * منتج بالمتر المربع: المقاس وعدد القطع مطلوبان، والكمية = (العرض/100) ×
     * (الطول/100) × عدد القطع بالمتر المربع، و`selling_price` سعرُ المتر لا
     * سعرُ القطعة. ومنتج القطعة يبقى كما كان: الكمية هي المُرسَلة، ولا مقاس له.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: float, 1: ?float, 2: ?float, 3: ?int}
     */
    private function resolveLineQuantity(array $line, Product $product): array
    {
        if (! $product->is_sqm) {
            return [round((float) $line['qty'], 2), null, null, null];
        }

        $widthCm = isset($line['width_cm']) && $line['width_cm'] !== '' ? (float) $line['width_cm'] : null;
        $heightCm = isset($line['height_cm']) && $line['height_cm'] !== '' ? (float) $line['height_cm'] : null;

        if ($widthCm === null || $widthCm <= 0 || $heightCm === null || $heightCm <= 0) {
            throw ValidationException::withMessages([
                'lines' => "أدخل العرض والطول للمنتج \"{$product->name}\" المسعّر بالمتر المربع.",
            ]);
        }

        $pieces = max(1, (int) ($line['pieces'] ?? 1));

        return [
            round(($widthCm / 100) * ($heightCm / 100) * $pieces, 2),
            $widthCm,
            $heightCm,
            $pieces,
        ];
    }

    /**
     * Agent discount/rebate amount for a base: a percentage of the base, or a
     * flat SAR amount capped at the base. Mirrors the coupon fixed/percentage
     * rule so the two behave the same.
     */
    private function agentAmount(AgentDiscountTypeEnum $type, float $rate, float $base): float
    {
        $amount = $type === AgentDiscountTypeEnum::Fixed
            ? $rate
            : $base * $rate / 100;

        return round(min($amount, $base), 2);
    }

    /**
     * Trim the invoice's free-text remark, collapsing a blank field to null so
     * an untouched box never persists an empty string.
     */
    private function normalizeNotes(mixed $notes): ?string
    {
        if (! is_string($notes)) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed === '' ? null : $trimmed;
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
