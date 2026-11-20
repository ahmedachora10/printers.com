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

    /**
     * The super admin may pick several branches: each gets its own independent
     * copy of the category. None = one general category. A branch admin is
     * pinned to their branch whatever they post.
     */
    protected function prepareForValidation(): void
    {
        $this->pinBranchId();

        $ids = $this->user()?->roleName?->isBranchAdmin() || ! $this->has('branch_ids')
            ? [$this->input('branch_id')]
            : (array) $this->input('branch_ids');

        $this->merge(['branch_ids' => array_filter($ids)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchIds = $this->input('branch_ids') ?: [null];

        return [
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'distinct', 'exists:branches,id'],
            'name_ar' => [
                'required',
                'string',
                'max:255',
                // Against everything each target branch sees, general rows
                // included: the tree is additive, so a repeated name would just
                // show up twice with nothing to tell the two apart.
                ...array_map(fn (?int $id) => $this->uniqueInBranchView('catalog_categories', 'name_ar', $id), $branchIds),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
