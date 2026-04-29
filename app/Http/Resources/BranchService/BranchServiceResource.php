<?php

namespace App\Http\Resources\BranchService;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasPivot = $this->pivot !== null;

        return [
            'id'                => $hasPivot ? $this->pivot->id : $this->id,
            'branchId'          => $hasPivot ? $this->id : $this->branch_id,
            'branchName'        => $hasPivot ? $this->name : $this->branch?->name,
            'serviceTemplateId'   => $hasPivot ? $this->pivot->service_template_id : $this->service_template_id,
            'serviceTemplateName' => $hasPivot ? null : $this->serviceTemplate?->name,
            'baseCommissionPct' => (float) ($hasPivot ? $this->pivot->base_commission_pct : $this->base_commission_pct),
            'maxDiscountPct'    => (float) ($hasPivot ? $this->pivot->max_discount_pct : $this->max_discount_pct),
            'isTahazir'         => (bool) ($hasPivot ? $this->pivot->is_tahazir : $this->is_tahazir),
            'isActive'          => (bool) ($hasPivot ? $this->pivot->is_active : $this->is_active),
            'createdAt'         => ($hasPivot ? $this->pivot->created_at : $this->created_at)?->toISOString(),
            'updatedAt'         => ($hasPivot ? $this->pivot->updated_at : $this->updated_at)?->toISOString(),
        ];
    }
}
