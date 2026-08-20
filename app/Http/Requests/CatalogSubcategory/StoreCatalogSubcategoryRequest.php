<?php

namespace App\Http\Requests\CatalogSubcategory;

use App\Http\Requests\Concerns\PinsCatalogueBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogSubcategoryRequest extends FormRequest
{
    use PinsCatalogueBranch;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->pinBranchId();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:catalog_categories,id'],
            'branch_id' => $this->branchIdRules(),
            'name_ar' => [
                'required',
                'string',
                'max:255',
                // Unique inside the owning category, across the general rows
                // this branch inherits and the ones it added (تاسك 47).
                $this->uniqueInBranchView('catalog_subcategories', 'name_ar', $this->input('branch_id'))
                    ->where('category_id', $this->input('category_id')),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
