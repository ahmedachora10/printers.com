<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\ValidatesPriceTiers;
use App\Models\ProductCategory;
use App\Rules\VisibleToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesPriceTiers;

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
                    // فرع المنتج لا فرع المستخدم — السوبر أدمن بلا فرع.
                    ->where('branch_id', $this->route('product')?->branch_id)
                    ->ignore($this->route('product')),
            ],
            'name' => ['required', 'string', 'max:255'],
            // تاسك 237: فئةٌ يراها فرع المنتج (عامة أو تشمله).
            'category_id' => ['required', 'integer', new VisibleToBranch(ProductCategory::class, $this->route('product')?->branch_id)],
            'unit_id' => ['required', 'integer', 'exists:product_units,id'],
            // تاسك 51: منتج يُباع بالمتر المربع — سعره سعرُ المتر، ونقطة البيع
            // تطلب مقاسه لتشتقّ الكمية التي تُخصم من المخزون.
            'is_sqm' => ['boolean'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            // تاسك 241: شرائح السعر حسب الكمية.
            ...$this->priceTierRules(),
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePriceTiers();
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $v) => $this->priceTierCheck($v)];
    }
}
