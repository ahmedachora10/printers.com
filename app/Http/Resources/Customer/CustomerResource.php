<?php

namespace App\Http\Resources\Customer;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isSuperAdmin = $request->user()?->roleName?->isSuperAdmin() === true;
        // ما يخصّ الفرع من الحسابات المحمَّلة (حساب فرع المستخدم، أو فروعه كلها
        // للسوبر أدمن) — انظر Customer::accountsSummary().
        $summary = $this->relationLoaded('accounts') ? $this->accountsSummary() : null;
        $account = $summary['account'] ?? null;

        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'branchId' => $account?->branch_id,
            // Every other role is already locked to a single branch, so the column
            // would carry no information for them — omit it entirely.
            'branchName' => $this->when($isSuperAdmin && $summary !== null, fn () => $this->accounts
                ->map(fn ($a) => $a->branch?->name)->filter()->join('، ')),
            'branches' => $this->when($isSuperAdmin && $summary !== null, fn () => $this->accounts->map(fn ($a) => [
                'branchId' => $a->branch_id,
                'branchName' => $a->branch?->name,
                'pointsBalance' => (int) $a->points_balance,
                'cumulativeSpend' => $a->cumulative_spend,
                'tier' => ['value' => $a->tier->value, 'label' => $a->tier->label()],
                'creditLimit' => $a->credit_limit,
                'isActive' => $a->is_active,
            ])->values()),
            'customerType' => [
                'value' => $this->customer_type?->value,
                'label' => $this->customer_type?->label(),
            ],
            'companyName' => $this->company_name,
            'taxNumber' => $this->tax_number,
            'creditLimit' => $summary['credit_limit'] ?? null,
            'agentId' => $summary['agent_id'] ?? null,
            'agent' => $this->when($account?->relationLoaded('agent') === true, fn () => $account->agent ? [
                'id' => $account->agent->id,
                'name' => $account->agent->name,
            ] : null),
            'pointsBalance' => $summary['points_balance'] ?? 0,
            'cumulativeSpend' => $summary['cumulative_spend'] ?? 0,
            'tier' => [
                'value' => ($summary['tier'] ?? null)?->value,
                'label' => ($summary['tier'] ?? null)?->label(),
            ],
            'notes' => $summary['notes'] ?? null,
            'isActive' => $summary['is_active'] ?? false,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
