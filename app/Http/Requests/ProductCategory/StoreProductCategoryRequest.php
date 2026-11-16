<?php

namespace App\Http\Requests\ProductCategory;

use App\Http\Requests\Concerns\ValidatesBranchScope;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * تاسك 237 — قاعدة فئات المصروفات (238): مدير الفرع يكتب لفرعه وحده؛ السوبر أدمن يختار
 * فرعاً أو عدّة فروع، أو لا شيء فتكون الفئة عامة. والاسم فريد بين ما يراه أيّ فرعٍ من النطاق.
 */
class StoreProductCategoryRequest extends FormRequest
{
    use ValidatesBranchScope;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareBranchScope();
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

    protected function scopedRecord(): ?Model
    {
        return $this->route('productCategory');
    }

    protected function scopedModel(): string
    {
        return ProductCategory::class;
    }
}
