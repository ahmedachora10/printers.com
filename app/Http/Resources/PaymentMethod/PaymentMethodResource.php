<?php

namespace App\Http\Resources\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isActive' => $this->is_active,
            'requiresAttachment' => $this->requires_attachment,
            // النطاق: null = طريقة عامة يرثها كل فرع، وإلا فهي ملك فرعها (تاسك 59).
            'branchId' => $this->branch_id,
            'branchName' => $this->branch?->name,
            // هل يملك المستخدم الحالي تحريرها؟ الواجهة تُخفي أزرار ما لا يملكه.
            'canEdit' => $request->user()?->can('update', $this->resource) ?? false,
        ];
    }
}
