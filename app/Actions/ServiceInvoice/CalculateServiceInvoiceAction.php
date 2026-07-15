<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Agent\ResolveInvoiceAgentsAction;
use App\Actions\Loyalty\ApplyLoyaltyDiscountsAction;
use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Enums\CouponDiscountTypeEnum;
use App\Enums\CustomerTypeEnum;
use App\Models\BranchService;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Validation\ValidationException;

/**
 * Pure pricing pipeline for a service invoice — shared by create and edit so the
 * two can never drift. Resolves the customer, lines, commission, and the full
 * discount cascade (tier → coupon → agent → points → VAT), returning the invoice
 * attributes and line rows to persist. It performs no invoice/ledger writes; the
 * only side effect is finding-or-creating a walk-in customer from name/phone.
 *
 * @phpstan-type CalculatedLine array{branch_service_id:int, service_name:string, qty:int, unit_price:float, discount_pct:float, subtotal:float, commission_pct:float, commission_amount:float, is_tahazir:bool}
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
            $unitPrice = (float) $line['unit_price'];
            $discountPct = (float) ($line['discount_pct'] ?? 0);
            $maxDiscount = (float) $branchService->max_discount_pct;

            if ($maxDiscount > 0 && $discountPct > $maxDiscount) {
                throw ValidationException::withMessages([
                    'lines' => "الخصم على \"{$branchService->serviceTemplate?->name}\" يتجاوز الحد المسموح ({$maxDiscount}%).",
                ]);
            }

            $lineSubtotal = round($qty * $unitPrice * (1 - $discountPct / 100), 2);
            $commissionPct = (float) ($commissionRates[$branchService->id] ?? 0);
            $commissionAmount = round($lineSubtotal * $commissionPct / 100, 2);

            $subtotal += $lineSubtotal;

            $lines[] = [
                'branch_service_id' => $branchService->id,
                'service_name' => $branchService->serviceTemplate->name ?? 'خدمة',
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_pct' => $discountPct,
                'subtotal' => $lineSubtotal,
                'commission_pct' => $commissionPct,
                'commission_amount' => $commissionAmount,
                'is_tahazir' => $branchService->is_tahazir,
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
            ];
        }

        $agentDiscount = round(min($agentDiscount, $afterCoupon), 2);
        $afterAgent = round($afterCoupon - $agentDiscount, 2);

        $requestedPoints = (int) ($data['redeem_points'] ?? 0);
        [$pointsRedeemed, $pointsDiscount] = $this->loyalty->redemption($customer, $loyaltyEligible, $config, $requestedPoints, $afterAgent);

        $taxableBase = round($afterAgent - $pointsDiscount, 2);
        $vatAmount = round($taxableBase * $vatPct / 100, 2);
        $total = round($taxableBase + $vatAmount, 2);

        // Rebate is computed on the final total, independently per agent.
        foreach ($agents as $i => $agent) {
            if ($agent['mode'] === AgentDiscountModeEnum::Rebate) {
                $agentRows[$i]['rebate_amount'] = $this->agentAmount($agent['type'], $agent['rate'], $total);
            }
        }

        // Employee commission is earned on the net service value the customer
        // actually pays — after every invoice-level deduction (tier, coupon,
        // agent discount, points), pre-VAT. Each line's raw commission is scaled
        // by the ratio of that final taxable base to the gross subtotal, sharing
        // the reduction across lines proportionally; the scaled amounts are what
        // get persisted to the lines and the immutable commission ledger, so the
        // employee is actually paid the reduced figure. Agent rebate sits on top
        // of the total and leaves this base untouched.
        $commissionRatio = $subtotal > 0 ? $taxableBase / $subtotal : 0.0;
        $totalCommission = 0.0;

        foreach ($lines as $i => $line) {
            $scaledCommission = round($line['commission_amount'] * $commissionRatio, 2);
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
            ],
            'lines' => $lines,
            'agents' => $agentRows,
            'coupon' => $coupon,
            'pointsRedeemed' => $pointsRedeemed,
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
