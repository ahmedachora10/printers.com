<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Agent\ResolveInvoiceAgentsAction;
use App\Actions\Loyalty\ApplyLoyaltyDiscountsAction;
use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Enums\CouponDiscountTypeEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\LineAgentCommissionTypeEnum;
use App\Enums\ServicePricingTypeEnum;
use App\Models\Agent;
use App\Models\BranchService;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\User;
use App\Models\UserService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pure pricing pipeline for a service invoice — shared by create and edit so the
 * two can never drift. Resolves the customer, lines, commission, and the full
 * discount cascade (tier → coupon → agent → points → VAT), returning the invoice
 * attributes and line rows to persist. It performs no invoice/ledger writes; the
 * only side effect is finding-or-creating a walk-in customer from name/phone.
 *
 * @phpstan-type CalculatedLine array{branch_service_id:int, service_name:string, notes:?string, qty:int, unit_price:float, width_cm:?float, height_cm:?float, discount_pct:float, subtotal:float, commission_pct:float, commission_amount:float, is_tahazir:bool, agent_id:?int, agent_commission_type:?LineAgentCommissionTypeEnum, agent_commission_value:?float, agent_commission_amount:float}
 */
class CalculateServiceInvoiceAction
{
    public function __construct(
        private readonly ResolveInvoiceAgentsAction $resolveAgents,
        private readonly ApplyLoyaltyDiscountsAction $loyalty,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, lines: list<CalculatedLine>, agents: list<array<string, mixed>>, coupon: ?Coupon, pointsRedeemed: int}
     */
    public function handle(array $data, User $user, int $branchId, float $vatPct): array
    {
        $customerId = $this->resolveCustomerId($data, $branchId);

        // Load the branch services referenced by the lines so we can resolve
        // their name, commission rate, tahazir flag and discount ceiling.
        $branchServiceIds = collect((array) $data['lines'])->pluck('branch_service_id')->unique();

        $branchServices = BranchService::query()
            ->where('branch_id', $branchId)
            ->whereIn('id', $branchServiceIds)
            ->with('serviceTemplate:id,name')
            ->get()
            ->keyBy('id');

        // Per-employee commission rates for this employee. A service with no
        // row earns 0% commission for them (zero fallback, not the base rate).
        $commissionRates = UserService::query()
            ->where('user_id', $user->id)
            ->whereIn('branch_service_id', $branchServiceIds)
            ->pluck('commission_override_pct', 'branch_service_id');

        $lineAgents = $this->resolveLineAgents($data, $branchId);

        $lines = [];
        $subtotal = 0.0;

        foreach ($data['lines'] as $line) {
            /** @var BranchService|null $branchService */
            $branchService = $branchServices->get($line['branch_service_id']);

            if (! $branchService || ! $branchService->is_active) {
                throw ValidationException::withMessages([
                    'lines' => 'إحدى الخدمات غير متاحة في هذا الفرع.',
                ]);
            }

            $qty = (int) $line['qty'];
            $discountPct = (float) ($line['discount_pct'] ?? 0);
            $maxDiscount = (float) $branchService->max_discount_pct;

            if ($maxDiscount > 0 && $discountPct > $maxDiscount) {
                throw ValidationException::withMessages([
                    'lines' => "الخصم على \"{$branchService->serviceTemplate?->name}\" يتجاوز الحد المسموح ({$maxDiscount}%).",
                ]);
            }

            // Sqm-priced services derive the per-piece price from the entered
            // dimensions server-side — the client figure is never trusted. The
            // per-piece price is rounded first so the JS preview (round2 on the
            // same formula) and the stored DECIMAL(12,2) always agree.
            $widthCm = isset($line['width_cm']) && $line['width_cm'] !== '' ? (float) $line['width_cm'] : null;
            $heightCm = isset($line['height_cm']) && $line['height_cm'] !== '' ? (float) $line['height_cm'] : null;

            if ($branchService->pricing_type === ServicePricingTypeEnum::Sqm) {
                if ($widthCm === null || $widthCm <= 0 || $heightCm === null || $heightCm <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => "أدخل العرض والطول لخدمة \"{$branchService->serviceTemplate?->name}\" المسعّرة بالمتر المربع.",
                    ]);
                }

                $unitPrice = round(($widthCm / 100) * ($heightCm / 100) * (float) $branchService->price_per_sqm, 2);
            } else {
                $widthCm = null;
                $heightCm = null;
                $unitPrice = (float) $line['unit_price'];
            }

            $lineSubtotal = round($qty * $unitPrice * (1 - $discountPct / 100), 2);
            $commissionPct = (float) ($commissionRates[$branchService->id] ?? 0);
            $commissionAmount = round($lineSubtotal * $commissionPct / 100, 2);

            // تكلفة الخامات: المواد التي استهلكها تنفيذ هذا السطر. القيمة تأتي من
            // تعريف الخدمة افتراضياً وتبقى قابلة للتعديل في نقطة البيع، وقد تُفعَّل
            // لخدمة بلا خامات معرّفة (خامات استثنائية) أو تُطفأ لخدمة تحملها.
            // المبلغ للوحدة الواحدة فيُضرب في الكمية.
            [$materialsCost, $materialsTotal] = $this->lineMaterials($line, $branchService, $qty);

            [$lineAgentId, $agentCommissionType, $agentCommissionValue, $agentCommissionAmount] =
                $this->lineAgentCommission($line, $branchService, $lineAgents, $lineSubtotal, $qty, $widthCm, $heightCm, $vatPct);

            $subtotal += $lineSubtotal;

            $lines[] = [
                'branch_service_id' => $branchService->id,
                'service_name' => $branchService->serviceTemplate->name ?? 'خدمة',
                // Free-text detail typed at the POS; carried through untouched.
                'notes' => $this->normalizeNotes($line['notes'] ?? null),
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'discount_pct' => $discountPct,
                'subtotal' => $lineSubtotal,
                'commission_pct' => $commissionPct,
                'commission_amount' => $commissionAmount,
                'materials_cost' => $materialsCost,
                'materials_total' => $materialsTotal,
                'is_tahazir' => $branchService->is_tahazir,
                'agent_id' => $lineAgentId,
                'agent_commission_type' => $agentCommissionType,
                'agent_commission_value' => $agentCommissionValue,
                'agent_commission_amount' => $agentCommissionAmount,
            ];
        }

        $subtotal = round($subtotal, 2);

        $customer = $customerId !== null ? Customer::find($customerId) : null;
        $config = LoyaltyConfig::forBranch($branchId);

        $agents = $this->resolveAgents->handle(
            isset($data['agent_ids']) && is_array($data['agent_ids']) ? $data['agent_ids'] : null,
            $branchId,
        );

        // Loyalty benefits (tier discount, redemption) apply only to eligible
        // customers: individual, not agent-linked, on a non-agent invoice —
        // B2B sales are settled via agent terms instead.
        $loyaltyEligible = $agents === []
            && $customer !== null
            && $customer->customer_type === CustomerTypeEnum::Individual
            && $customer->agent_id === null;

        // Discount pipeline: subtotal → tier → coupon → agent → points → VAT.
        [$tierPct, $tierDiscount] = $this->loyalty->tierDiscount($customer, $loyaltyEligible, $config, $subtotal);
        $afterTier = round($subtotal - $tierDiscount, 2);

        [$coupon, $couponDiscount] = $this->resolveCoupon($data, $branchId, $afterTier);
        $afterCoupon = round($afterTier - $couponDiscount, 2);

        // discount mode reduces the taxable base; rebate is recorded per agent
        // after the total but never deducted from it. With several agents the
        // per-agent discount amounts are summed (capped at the base), each rate
        // read as a percentage or a flat SAR amount per the agent's type.
        $agentRows = [];
        $agentDiscount = 0.0;

        foreach ($agents as $agent) {
            $discount = $agent['mode'] === AgentDiscountModeEnum::Discount
                ? $this->agentAmount($agent['type'], $agent['rate'], $afterCoupon)
                : 0.0;

            $agentDiscount += $discount;

            $agentRows[] = [
                'agent_id' => $agent['agentId'],
                'discount_mode' => $agent['mode'],
                'discount_type' => $agent['type'],
                'rate' => $agent['rate'],
                'discount_amount' => round($discount, 2),
                'rebate_amount' => 0.0, // filled once the total is known
                'line_commission_amount' => 0.0, // filled from the line commissions below
            ];
        }

        $agentDiscount = round(min($agentDiscount, $afterCoupon), 2);
        $afterAgent = round($afterCoupon - $agentDiscount, 2);

        $requestedPoints = (int) ($data['redeem_points'] ?? 0);
        [$pointsRedeemed, $pointsDiscount] = $this->loyalty->redemption($customer, $loyaltyEligible, $config, $requestedPoints, $afterAgent);

        $taxableBase = round($afterAgent - $pointsDiscount, 2);
        $vatAmount = round($taxableBase * $vatPct / 100, 2);
        $total = round($taxableBase + $vatAmount, 2);

        // Every commission on this invoice is earned on the value net of VAT: the
        // tax belongs to the state, not to the sale, so it is stripped out of the
        // base before any percentage is taken. The prices entered at the POS are
        // treated as VAT-inclusive for this purpose only — the customer's total is
        // still taxableBase + VAT and is unaffected.
        $netBeforeVat = round($taxableBase / (1 + $vatPct / 100), 2);

        // Rebate is computed on that net value, independently per agent.
        foreach ($agents as $i => $agent) {
            if ($agent['mode'] === AgentDiscountModeEnum::Rebate) {
                $agentRows[$i]['rebate_amount'] = $this->agentAmount($agent['type'], $agent['rate'], $netBeforeVat);
            }
        }

        // Per-line commission owners accrue onto the same pivot rows: an agent
        // already attached at invoice level keeps their row (merged), one picked
        // only on lines gets a row with no invoice-level discount/rebate effect.
        // Like rebates, the amount never touches the customer's total and is
        // settled with agent payments once the invoice is paid.
        $lineCommissions = [];

        foreach ($lines as $line) {
            if ($line['agent_id'] !== null && $line['agent_commission_amount'] > 0) {
                $lineCommissions[$line['agent_id']] = round(
                    ($lineCommissions[$line['agent_id']] ?? 0.0) + $line['agent_commission_amount'],
                    2,
                );
            }
        }

        foreach ($agentRows as $i => $row) {
            if (isset($lineCommissions[$row['agent_id']])) {
                $agentRows[$i]['line_commission_amount'] = $lineCommissions[$row['agent_id']];
                unset($lineCommissions[$row['agent_id']]);
            }
        }

        foreach ($lineCommissions as $agentId => $amount) {
            $profile = $lineAgents->get($agentId)?->agentProfile;

            $agentRows[] = [
                'agent_id' => $agentId,
                'discount_mode' => $profile->discount_mode ?? AgentDiscountModeEnum::Rebate,
                'discount_type' => $profile->discount_type ?? AgentDiscountTypeEnum::Percentage,
                'rate' => (float) ($profile->rate ?? 0),
                'discount_amount' => 0.0,
                'rebate_amount' => 0.0,
                'line_commission_amount' => $amount,
            ];
        }

        // Employee commission is earned on the net service value the customer
        // actually pays — after every invoice-level deduction (tier, coupon,
        // agent discount, points), after VAT is stripped out, and after the cost
        // of the materials the centre consumed is taken off: that cost is not
        // profit, so no commission is owed on it. Each line takes its share of
        // the net value proportionally to its gross subtotal, subtracts its own
        // materials, and only then applies the employee's rate. The results are
        // what get persisted to the lines and the immutable commission ledger, so
        // the employee is actually paid the reduced figure. Agent rebate and the
        // per-line commission owner sit outside this base and are untouched.
        //
        // Materials are deliberately NOT scaled by the ratio: they are a real SAR
        // cost that does not shrink because the customer was given a discount. A
        // heavily discounted line can therefore have its whole base eaten — the
        // clamp stops it at zero rather than letting commission go negative.
        $commissionRatio = $subtotal > 0 ? $netBeforeVat / $subtotal : 0.0;
        $totalCommission = 0.0;

        foreach ($lines as $i => $line) {
            $lineNet = round($line['subtotal'] * $commissionRatio, 2);
            $materials = min($line['materials_total'], $lineNet);
            $commissionBase = round($lineNet - $materials, 2);

            $scaledCommission = round($commissionBase * $line['commission_pct'] / 100, 2);
            $lines[$i]['commission_amount'] = $scaledCommission;
            $totalCommission += $scaledCommission;
        }

        $totalCommission = round($totalCommission, 2);

        return [
            'attributes' => [
                'customer_id' => $customerId,
                'coupon_id' => $coupon?->id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'subtotal' => $subtotal,
                'tier_discount_pct' => $tierPct,
                'tier_discount_amount' => $tierDiscount,
                'coupon_discount' => $couponDiscount,
                'agent_discount' => $agentDiscount,
                'points_redeemed' => $pointsRedeemed,
                'points_discount' => $pointsDiscount,
                'vat_pct' => $vatPct,
                'vat_amount' => $vatAmount,
                'total_amount' => $total,
                'employee_commission' => $totalCommission,
                // Invoice-level remark for the customer — carried through
                // untouched, like the per-line detail.
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                // موعد تسليم العمل، بدقّة الدقيقة كما اختاره الكاشير.
                'delivery_at' => $this->normalizeDeliveryAt($data['delivery_at'] ?? null),
            ],
            'lines' => $lines,
            'agents' => $agentRows,
            'coupon' => $coupon,
            'pointsRedeemed' => $pointsRedeemed,
        ];
    }

    /**
     * Load the active branch agents referenced by the lines' commission owners,
     * keyed by id, with their profiles for the pivot-row snapshot.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Agent>
     */
    private function resolveLineAgents(array $data, int $branchId): Collection
    {
        $ids = collect((array) $data['lines'])
            ->map(fn ($line) => (int) ($line['agent_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return Agent::query()
            ->forBranch($branchId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Resolve a line's materials cost. The POS toggle decides whether the line
     * carries materials at all and is independent of the service definition: it
     * is merely prefilled from it, so a service with no materials can still be
     * charged an exceptional one and a service that normally has them can be
     * cleared. The submitted amount wins whenever the toggle is on; the service
     * default only fills in when the POS sent nothing.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: float, 1: float} per-unit cost, line total
     */
    private function lineMaterials(array $line, BranchService $branchService, int $qty): array
    {
        $hasMaterials = array_key_exists('has_materials', $line)
            ? (bool) $line['has_materials']
            : (bool) $branchService->has_materials;

        if (! $hasMaterials) {
            return [0.0, 0.0];
        }

        $cost = isset($line['materials_cost']) && $line['materials_cost'] !== ''
            ? (float) $line['materials_cost']
            : (float) $branchService->materials_cost;

        $cost = round(max(0.0, $cost), 2);

        return [$cost, round($cost * $qty, 2)];
    }

    /**
     * Resolve a line's commission-owner terms and compute the amount. The value
     * is whatever the cashier submitted (prefill is a client-side convenience);
     * the amount is always derived here: percentage of the line subtotal net of
     * VAT (post line-discount, never scaled by the invoice-level cascade), a
     * fixed SAR amount per piece, or a per-sqm rate over the line's total area.
     *
     * Only the percentage case divides out VAT — a fixed or per-sqm rate is an
     * agreed SAR figure with no tax inside it to strip.
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<int, Agent>  $lineAgents
     * @return array{0: ?int, 1: ?LineAgentCommissionTypeEnum, 2: ?float, 3: float}
     */
    private function lineAgentCommission(
        array $line,
        BranchService $branchService,
        Collection $lineAgents,
        float $lineSubtotal,
        int $qty,
        ?float $widthCm,
        ?float $heightCm,
        float $vatPct,
    ): array {
        $agentId = (int) ($line['agent_id'] ?? 0);

        if ($agentId === 0) {
            return [null, null, null, 0.0];
        }

        // Presence in the collection already means the agent is linked to this
        // branch — resolveLineAgents() filters on that link.
        $agent = $lineAgents->get($agentId);

        if (! $agent) {
            throw ValidationException::withMessages([
                'lines' => 'أحد أصحاب العمولة المحددين غير صالح لهذا الفرع.',
            ]);
        }

        $type = LineAgentCommissionTypeEnum::tryFrom((string) ($line['agent_commission_type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages([
                'lines' => 'نوع عمولة صاحب العمولة غير صالح.',
            ]);
        }

        $value = (float) ($line['agent_commission_value'] ?? 0);

        if ($type === LineAgentCommissionTypeEnum::Percentage && $value > 100) {
            throw ValidationException::withMessages([
                'lines' => 'نسبة عمولة صاحب العمولة يجب ألا تتجاوز 100%.',
            ]);
        }

        if ($type === LineAgentCommissionTypeEnum::PerSqm
            && $branchService->pricing_type !== ServicePricingTypeEnum::Sqm) {
            throw ValidationException::withMessages([
                'lines' => 'عمولة المتر المربع متاحة فقط للخدمات المسعّرة بالمتر المربع.',
            ]);
        }

        $lineNetBeforeVat = round($lineSubtotal / (1 + $vatPct / 100), 2);

        $amount = match ($type) {
            LineAgentCommissionTypeEnum::Percentage => round($lineNetBeforeVat * $value / 100, 2),
            LineAgentCommissionTypeEnum::Fixed => round($value * $qty, 2),
            LineAgentCommissionTypeEnum::PerSqm => round(
                $qty * (($widthCm ?? 0) / 100) * (($heightCm ?? 0) / 100) * $value,
                2,
            ),
        };

        return [$agentId, $type, $value, $amount];
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
     * Trim a free-text field (a line's detail or the invoice's own remark),
     * collapsing a blank field to null so an untouched box never persists an
     * empty string.
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
     * موعد التسليم كما وصل من الواجهة («YYYY-MM-DD HH:MM») إلى تاريخ بدقّة
     * الدقيقة (الثواني مصفّرة)، أو null لصندوق فارغ. الصيغة تحقّقت في الـ Request.
     */
    private function normalizeDeliveryAt(mixed $deliveryAt): ?CarbonImmutable
    {
        if (! is_string($deliveryAt) || trim($deliveryAt) === '') {
            return null;
        }

        return CarbonImmutable::parse($deliveryAt)->startOfMinute();
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
        $taxNumber = trim((string) ($data['walkin_tax_number'] ?? ''));

        if ($name === '' && $phone === '') {
            return null;
        }

        if ($phone !== '') {
            $existing = Customer::query()
                ->where('branch_id', $branchId)
                ->where('phone', $phone)
                ->first();

            if ($existing) {
                // Fill the tax number if the customer doesn't have one yet —
                // never overwrite an already-recorded value.
                if ($taxNumber !== '' && $existing->tax_number === null) {
                    $existing->update(['tax_number' => $taxNumber]);
                }

                return $existing->id;
            }
        }

        return Customer::create([
            'full_name' => $name !== '' ? $name : 'عميل عابر',
            'phone' => $phone !== '' ? $phone : null,
            'tax_number' => $taxNumber !== '' ? $taxNumber : null,
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
}
