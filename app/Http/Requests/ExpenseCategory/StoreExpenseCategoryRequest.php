<?php

namespace App\Http\Requests\ExpenseCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * تاسك 102: مدير الفرع يكتب لفرعه وحده — `branch_id` يُثبَّت من المستخدم ولا
     * يُقرأ من الطلب. السوبر أدمن يختار فرعاً أو يتركه فارغاً فتكون الفئة عامة.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        $this->merge([
            'branch_id' => $user?->roleName->isSuperAdmin() ? ($this->input('branch_id') ?: null) : $user?->branchId,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->input('branch_id');

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                // فريد بين ما يراه الفرع: العامة + فئاته. فرعان يسمّيان «كهرباء» معاً.
                Rule::unique('expense_categories', 'name')->where(fn ($q) => $branchId === null
                    ? $q->whereNull('branch_id')
                    : $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId))),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'يوجد فئة مصروف بهذا الاسم متاحة لهذا الفرع بالفعل.',
        ];
    }
}
