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
        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'branchId' => $this->branch_id,
            // Every other role is already locked to a single branch, so the column
            // would carry no information for them — omit it entirely.
            'branchName' => $this->when(
                $request->user()?->roleName?->isSuperAdmin() === true,
                fn () => $this->whenLoaded('branch', fn () => $this->branch?->name),
            ),
            'customerType' => [
                'value' => $this->customer_type?->value,
                'label' => $this->customer_type?->label(),
            ],
            'companyName' => $this->company_name,
            'taxNumber' => $this->tax_number,
            'creditLimit' => $this->credit_limit,
            'agentId' => $this->agent_id,
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent?->id,
                'name' => $this->agent?->name,
            ]),
            'pointsBalance' => $this->points_balance,
            'cumulativeSpend' => $this->cumulative_spend,
            'tier' => [
                'value' => $this->tier?->value,
                'label' => $this->tier?->label(),
            ],
            'notes' => $this->notes,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
