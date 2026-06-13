<?php

namespace App\Http\Resources\Branch;

use App\Http\Resources\City\CityResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Branch
 */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'cityId'           => $this->city_id,
            'city'             => new CityResource($this->whenLoaded('city')),
            'ownerId'          => $this->owner_id,
            'owner'            => $this->whenLoaded('owner', fn () => [
                'id'   => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'phone'            => $this->phone,
            'address'          => $this->address,
            'businessType'     => $this->business_type,
            'commercialRegNo'  => $this->commercial_reg_no,
            'taxNumber'        => $this->tax_number,
            'vatRateOverride'  => (float) $this->vat_rate_override,
            'isActive'         => $this->is_active,
            'logoUrl'          => $this->getFirstMediaUrl('logo'),
            'createdAt'        => $this->created_at?->toISOString(),
            'updatedAt'        => $this->updated_at?->toISOString(),
        ];
    }
}
