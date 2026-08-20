<?php

namespace App\Http\Requests\CatalogCategory;

use App\Http\Requests\Concerns\PinsCatalogueBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogCategoryRequest extends FormRequest
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
            'branch_id' => $this->branchIdRules(),
            'name_ar' => [
                'required',
                'string',
                'max:255',
                // Against everything this branch sees, general rows included:
                // the tree is additive, so a repeated name would just show up
                // twice with nothing to tell the two apart.
                $this->uniqueInBranchView('catalog_categories', 'name_ar', $this->input('branch_id')),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
