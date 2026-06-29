<?php

namespace App\Http\Requests\CatalogPrice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCatalogPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $price = $this->route('price');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('catalog_prices', 'name')
                    ->where(fn ($q) => $q->where('subcategory_id', $price->subcategory_id))
                    ->ignore($price->id),
            ],
            'min_price' => ['required', 'numeric', 'min:0'],
            'max_price' => ['required', 'numeric', 'min:0', 'gte:min_price'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
