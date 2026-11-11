<?php

namespace App\Http\Requests\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Validation\Validator;

/**
 * قواعد الإنشاء ورسائله نفسها. تاسك 231: السوبر أدمن ينقل النطاق عند التعديل
 * (فارغ = عامة)؛ غيره يبقى على نطاق الفئة مهما أرسل. والتفرّد يُقاس على النطاق الجديد.
 */
class UpdateExpenseCategoryRequest extends StoreExpenseCategoryRequest
{
    protected function prepareForValidation(): void
    {
        // طلبٌ بلا branch_id (تعديل الاسم وحده) لا يحوّل الفئة إلى عامة.
        $this->merge([
            'branch_id' => $this->user()?->roleName->isSuperAdmin() && $this->has('branch_id')
                ? ($this->input('branch_id') ?: null)
                : $this->category()->branch_id,
        ]);
    }

    /**
     * حصر فئةٍ في فرعٍ ولها مصروفات في غيره يتركها على فئةٍ لا يراها أصحابها.
     * التحويل إلى «عامة» مسموح دائماً.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $branchId = $this->input('branch_id');

            if ($branchId !== null && $this->category()->expenses()->where('branch_id', '!=', $branchId)->exists()) {
                $validator->errors()->add('branch_id', 'لا يمكن حصر الفئة في هذا الفرع: لها مصروفات في فروع أخرى.');
            }
        }];
    }

    private function category(): ExpenseCategory
    {
        return $this->route('expenseCategory');
    }
}
