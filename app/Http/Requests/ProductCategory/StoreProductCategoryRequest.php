<?php

namespace App\Http\Requests\ProductCategory;

use App\Http\Requests\Concerns\ValidatesBranchScope;
use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * إنشاء فئة المنتج وتعديلها (تاسك 237) — قاعدة فئات المصروفات: مدير الفرع لفرعه وحده؛
 * السوبر أدمن فرعٌ أو عدّة فروع أو عامة. الحصر يُرفض إن كانت للفئة منتجات خارج النطاق.
 */
class StoreProductCategoryRequest extends FormRequest
{
    use ValidatesBranchScope;

    protected string $scopedModel = ProductCategory::class;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->branchScopeRules(),
            'name' => ['required', 'string', 'max:255', $this->uniqueNameInScope('يوجد فئة منتج بهذا الاسم متاحة لهذا الفرع بالفعل.')],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->narrowingCheck('منتجات');
    }
}
