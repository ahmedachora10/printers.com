<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Agent\ResolveInvoiceAgentAction;
use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Enums\AgentDiscountModeEnum;
use App\Enums\CommissionSourceTypeEnum;
use App\Enums\CouponDiscountTypeEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateServiceInvoiceAction
{
    public function __construct(
        private readonly ResolveInvoiceAgentAction $resolveAgent,
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): ServiceInvoice
    {
        $user = auth()->user();
        $branchId = $user->branchId;
        $branch = Branch::findOrFail($branchId);
        $vatPct = (float) $branch->vat_rate_override;

        return DB::transaction(function () use ($data, $user, $branchId, $vatPct) {
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

            $lines = [];
            $subtotal = 0.0;
            $totalCommission = 0.0;

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
                $commissionPct = (float) $branchService->base_commission_pct;
                $commissionAmount = round($lineSubtotal * $commissionPct / 100, 2);

                $subtotal += $lineSubtotal;
                $totalCommission += $commissionAmount;

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
            $totalCommission = round($totalCommission, 2);

            [$coupon, $couponDiscount] = $this->resolveCoupon($data, $branchId, $subtotal);

            [$agentId, $agentMode, $agentRate] = $this->resolveAgent->handle(
                isset($data['agent_id']) ? (int) $data['agent_id'] : null,
                $branchId,
            );

            // discount mode reduces the taxable base; rebate is recorded on the
            // invoice after the total but never deducted from it.
            $afterCoupon = round($subtotal - $couponDiscount, 2);
            $agentDiscount = $agentMode === AgentDiscountModeEnum::Discount
                ? round($afterCoupon * $agentRate / 100, 2)
                : 0.0;

            $taxableBase = round($afterCoupon - $agentDiscount, 2);
            $vatAmount = round($taxableBase * $vatPct / 100, 2);
            $total = round($taxableBase + $vatAmount, 2);

            $agentRebate = $agentMode === AgentDiscountModeEnum::Rebate
                ? round($total * $agentRate / 100, 2)
                : 0.0;

            $status = InvoiceStatusEnum::from($data['status']);

            $invoice = ServiceInvoice::create([
                'invoice_number' => $this->generateInvoiceNumber($branchId),
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'agent_id' => $agentId,
                'coupon_id' => $coupon?->id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'subtotal' => $subtotal,
                'coupon_discount' => $couponDiscount,
                'agent_discount' => $agentDiscount,
                'agent_rebate' => $agentRebate,
                'vat_pct' => $vatPct,
                'vat_amount' => $vatAmount,
                'total_amount' => $total,
                'employee_commission' => $totalCommission,
                'status' => $status,
                'paid_at' => $status === InvoiceStatusEnum::PAID ? now() : null,
            ]);

            foreach ($lines as $line) {
                /** @var ServiceInvoiceLine $invoiceLine */
                $invoiceLine = $invoice->lines()->create([
                    'branch_service_id' => $line['branch_service_id'],
                    'service_name' => $line['service_name'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => $line['discount_pct'],
                    'subtotal' => $line['subtotal'],
                    'commission_pct' => $line['commission_pct'],
                    'commission_amount' => $line['commission_amount'],
                ]);

                // One immutable ledger row per line. Tier resolution will populate
                // tier_applied once commission tiers are built (M07/M15).
                CommissionLedger::create([
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                    'invoice_line_id' => $invoiceLine->id,
                    'invoice_line_type' => ServiceInvoiceLine::class,
                    'amount' => $line['commission_amount'],
                    'is_tahazir' => $line['is_tahazir'],
                    'tier_applied' => null,
                    'source_type' => CommissionSourceTypeEnum::STANDARD,
                    'earned_at' => now(),
                ]);
            }

            if ($coupon) {
                $coupon->increment('used_count');
            }

            // Loyalty points accrue only on paid invoices for eligible
            // individual customers; the action no-ops otherwise.
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
        $seq = ServiceInvoice::withTrashed()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('SINV-%03d-%05d', $branchId, $seq);
    }
}
