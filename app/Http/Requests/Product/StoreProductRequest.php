<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'             => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku'),
            ],
            'name'            => ['required', 'string', 'max:255'],
            'category_id'     => ['required', 'integer', 'exists:product_categories,id'],
            'unit_id'         => ['required', 'integer', 'exists:product_units,id'],
            'cost_price'      => ['required', 'numeric', 'min:0'],
            'selling_price'   => ['required', 'numeric', 'min:0'],
            'min_stock_level' => ['nullable', 'integer', 'min:0'],
            'barcode'         => ['nullable', 'string', 'max:100'],
            'is_active'       => ['boolean'],
        ];
    }
}
