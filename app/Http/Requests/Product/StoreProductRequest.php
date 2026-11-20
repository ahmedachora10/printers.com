<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\ValidatesPriceTiers;
use App\Models\ProductCategory;
use App\Rules\VisibleToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ValidatesPriceTiers;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * الفروع التي يُنشأ فيها المنتج — صفٌّ لكل فرع. السوبر أدمن لا فرع له فيختار
     * فرعاً أو أكثر؛ ومدير الفرع مثبَّتٌ على فرعه.
     *
     * @return array<int, int>
     */
    public function branchIds(): array
    {
        if ($this->user()->roleName?->isSuperAdmin()) {
            return array_map('intval', (array) $this->input('branch_ids', []));
        }

        return array_filter([$this->user()->branchId]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchIds = $this->branchIds();

        return [
            // مدير الفرع لا يرسلها، وإن أرسلها تُتجاهل — branchIds() تثبّته على فرعه.
            'branch_ids' => [Rule::requiredIf((bool) $this->user()->roleName?->isSuperAdmin()), 'array'],
            'branch_ids.*' => ['integer', 'distinct', 'exists:branches,id'],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->whereIn('branch_id', $branchIds),
            ],
            'name' => ['required', 'string', 'max:255'],
            // تاسك 237: فئةٌ يراها كل فرعٍ مختار (عامة أو تشمله).
            'category_id' => ['required', 'integer', ...array_map(
                fn (?int $id) => new VisibleToBranch(ProductCategory::class, $id),
                $branchIds ?: [null],
            )],
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
        return [
            fn (Validator $v) => $this->priceTierCheck($v),
            function (Validator $v) {
                if ($this->branchIds() === [] && ! $v->errors()->has('branch_ids')) {
                    $v->errors()->add('branch_ids', 'حسابك غير مرتبط بفرع، فلا وجهة للمنتج.');
                }
            },
        ];
    }
}
