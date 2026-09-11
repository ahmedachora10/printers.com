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
            // null = فئة عامة (تاسك 102).
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'canEdit' => $request->user()?->can('update', $this->resource) ?? false,
        ];
    }
}
