<?php

namespace App\Http\Resources\Deduction;

use App\Models\EmployeeDeduction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeDeduction
 */
class EmployeeDeductionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->user?->name,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'amount' => (float) $this->amount,
            'reason' => $this->reason->value,
            'reasonLabel' => $this->reason->label(),
            'reasonNote' => $this->reason_note,
            'reasonText' => $this->reasonLabel(),
            'deductedBy' => $this->deductedBy?->name,
            'deductedAt' => $this->deducted_at?->format('d/m/Y'),
            'notes' => $this->notes,
        ];
    }
}
