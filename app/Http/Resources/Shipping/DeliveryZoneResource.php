<?php

namespace App\Http\Resources\Shipping;

use App\Models\DeliveryZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryZone
 */
class DeliveryZoneResource extends JsonResource
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
            'fromKm' => $this->from_km !== null ? (float) $this->from_km : null,
            'toKm' => $this->to_km !== null ? (float) $this->to_km : null,
            // المدى مكتوبٌ للقراءة، يُشتقّ في الخادم مرّةً واحدة فلا تتضارب
            // صياغتُه بين شاشة الإدارة ومنتقي نقطة البيع وبيان التوصيل.
            'rangeLabel' => $this->rangeLabel(),
            'price' => (float) $this->price,
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'canEdit' => $request->user()?->can('update', $this->resource) ?? false,
        ];
    }
}
