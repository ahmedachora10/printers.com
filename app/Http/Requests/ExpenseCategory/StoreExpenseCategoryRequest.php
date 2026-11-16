<?php

namespace App\Http\Requests\ExpenseCategory;

use App\Http\Requests\Concerns\ValidatesBranchScope;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * تاسك 102/238: مدير الفرع يكتب لفرعه وحده؛ السوبر أدمن يختار فرعاً أو عدّة فروع،
 * أو لا شيء فتكون الفئة عامة. التعديل يرث القواعد ويستثني فئته (تاسك 231).
 */
class StoreExpenseCategoryRequest extends FormRequest
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
            // فريد بين ما يراه أيّ فرعٍ من النطاق. فرعان منفصلان يسمّيان «كهرباء» معاً.
            'name' => ['required', 'string', 'max:255', $this->uniqueNameInScope('يوجد فئة مصروف بهذا الاسم متاحة لهذا الفرع بالفعل.')],
            'is_active' => ['boolean'],
        ];
    }

    protected function scopedRecord(): ?Model
    {
        return $this->route('expenseCategory');
    }

    protected function scopedModel(): string
    {
        return ExpenseCategory::class;
    }
}
