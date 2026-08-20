<?php

namespace App\Http\Requests\CatalogPrice;

use App\Http\Requests\Concerns\PinsCatalogueBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogPriceRequest extends FormRequest
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
            'subcategory_id' => ['required', 'integer', 'exists:catalog_subcategories,id'],
            'branch_id' => $this->branchIdRules(),
            'name' => [
                'required',
                'string',
                'max:255',
                // Scoped to the owner alone, unlike the tree: a branch price is
                // *meant* to repeat the general name it overrides (تاسك 47).
                $this->uniqueInBranchScope('catalog_prices', 'name', $this->input('branch_id'))
                    ->where('subcategory_id', $this->input('subcategory_id')),
            ],
            'min_price' => ['required', 'numeric', 'min:0'],
            'max_price' => ['required', 'numeric', 'min:0', 'gte:min_price'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
