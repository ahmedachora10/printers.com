<?php

namespace App\Http\Resources\Coupon;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Coupon
 */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'code'               => $this->code,
            'branchId'           => $this->branch_id,
            'discountType'       => [
                'value' => $this->discount_type->value,
                'label' => $this->discount_type->label(),
            ],
            'discountValue'      => $this->discount_value,
            'capacity'           => $this->capacity,
            'usedCount'          => $this->used_count,
            'remainingCapacity'  => $this->capacity !== null
                ? max(0, $this->capacity - $this->used_count)
                : null,
            'expiresAt'          => $this->expires_at?->toISOString(),
            'isActive'           => $this->is_active,
            'createdAt'          => $this->created_at?->toISOString(),
        ];
    }
}
