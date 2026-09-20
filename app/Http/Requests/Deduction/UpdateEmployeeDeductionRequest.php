<?php

namespace App\Http\Requests\Deduction;

use App\Enums\DeductionReasonEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * تاسك 126 — تعديل قيدٍ مسجَّل. نفس قواعد `Store` إلا `user_id`: الموظف
 * المحسوم عليه لا يُغيَّر، فتغييره نقلُ مالٍ من ذمّةٍ إلى أخرى — والصحيح حذفٌ
 * وتسجيلٌ جديد. وأُضيف `deducted_at` لأنه ممّا يُصحَّح فعلاً.
 */
class UpdateEmployeeDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', new Enum(DeductionReasonEnum::class)],
            'reason_note' => [
                Rule::requiredIf(fn () => $this->input('reason') === DeductionReasonEnum::Other->value),
                'nullable',
                'string',
                'max:255',
            ],
            'deducted_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'amount' => 'القيمة',
            'reason' => 'السبب',
            'reason_note' => 'شرح السبب',
            'deducted_at' => 'التاريخ',
            'notes' => 'الملاحظات',
        ];
    }
}
