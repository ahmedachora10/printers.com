<?php

namespace App\Http\Requests\CatalogPrice;

use App\Http\Requests\Concerns\PinsCatalogueBranch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogPriceRequest extends FormRequest
{
    use PinsCatalogueBranch;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A row never changes owner: uniqueness stays inside the branch (or
        // inside the general rows) the price already belongs to.
        $price = $this->route('price');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                $this->uniqueInBranchScope('catalog_prices', 'name', $price->branch_id, $price->id)
                    ->where('subcategory_id', $price->subcategory_id),
            ],
            'min_price' => ['required', 'numeric', 'min:0'],
            'max_price' => ['required', 'numeric', 'min:0', 'gte:min_price'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
