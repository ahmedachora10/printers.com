<?php

namespace App\Http\Resources\Commission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->whenLoaded('user', fn () => $this->user->name),
            'branchId' => $this->branch_id,
            'periodStart' => $this->period_start?->toDateString(),
            'periodEnd' => $this->period_end?->toDateString(),
            'totalAmount' => $this->total_amount,
            'paidByName' => $this->whenLoaded('paidBy', fn () => $this->paidBy->name),
            'paidAt' => $this->paid_at?->toISOString(),
            'notes' => $this->notes,
        ];
    }
}
