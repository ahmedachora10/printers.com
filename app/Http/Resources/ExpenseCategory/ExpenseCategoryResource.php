<?php

namespace App\Http\Resources\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseCategory
 */
class ExpenseCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isActive' => $this->is_active,
            // تاسك 238: فارغة = فئة عامة.
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()),
            'canEdit' => $request->user()?->can('update', $this->resource) ?? false,
        ];
    }
}
