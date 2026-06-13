<?php

namespace App\Http\Resources\ServiceTemplate;

use App\Http\Resources\BranchService\BranchServiceResource;
use App\Models\ServiceTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceTemplate
 */
class ServiceTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'isActive' => $this->is_active,
            'branches' => BranchServiceResource::collection($this->whenLoaded('branches')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
