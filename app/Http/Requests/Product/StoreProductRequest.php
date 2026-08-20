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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where('branch_id', auth()->user()->branchId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'unit_id' => ['required', 'integer', 'exists:product_units,id'],
            // تاسك 51: منتج يُباع بالمتر المربع — سعره سعرُ المتر، ونقطة البيع
            // تطلب مقاسه لتشتقّ الكمية التي تُخصم من المخزون.
            'is_sqm' => ['boolean'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
