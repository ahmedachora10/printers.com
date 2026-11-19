<?php

namespace App\Http\Requests\ExpenseCategory;

use App\Http\Requests\Concerns\ValidatesBranchScope;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * إنشاء فئة المصروف وتعديلها (تاسك 102/231/238): مدير الفرع يكتب لفرعه وحده؛ السوبر
 * أدمن يختار فرعاً أو عدّة فروع، أو لا شيء فتكون عامة. الحصر يُرفض إن كانت للفئة
 * مصروفات في فرعٍ خارج النطاق الجديد.
 */
class StoreExpenseCategoryRequest extends FormRequest
{
    use ValidatesBranchScope;

    protected string $scopedModel = ExpenseCategory::class;

    public function authorize(): bool
    {
        return true;
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

    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->narrowingCheck('مصروفات');
    }
}
