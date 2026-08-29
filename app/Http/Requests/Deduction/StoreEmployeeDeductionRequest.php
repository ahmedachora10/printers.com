<?php

namespace App\Http\Requests\Deduction;

use App\Enums\DeductionReasonEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreEmployeeDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', new Enum(DeductionReasonEnum::class)],
            // «حالات أخرى» بلا شرح ليست سبباً — نصّ العميل يطلب السبب والقيمة معاً.
            'reason_note' => [
                Rule::requiredIf(fn () => $this->input('reason') === DeductionReasonEnum::Other->value),
                'nullable',
                'string',
                'max:255',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'user_id' => 'الموظف',
            'amount' => 'القيمة',
            'reason' => 'السبب',
            'reason_note' => 'شرح السبب',
            'notes' => 'الملاحظات',
        ];
    }
}
