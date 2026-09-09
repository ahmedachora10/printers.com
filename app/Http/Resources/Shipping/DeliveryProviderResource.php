<?php

namespace App\Http\Resources\Shipping;

use App\Models\DeliveryProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryProvider
 */
class DeliveryProviderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'phone' => $this->phone,
            'notes' => $this->notes,
            'isActive' => $this->is_active,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            // الواجهة تُخفي أزرار ما لا يملكه المستخدم — والسياسة هي الفيصل
            // لا الدور، فلا يُكرَّر شرطُ الملكية في الواجهة.
            'canEdit' => $request->user()?->can('update', $this->resource) ?? false,
        ];
    }
}
