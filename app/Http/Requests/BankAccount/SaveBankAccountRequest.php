<?php

namespace App\Http\Requests\BankAccount;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تاسك 187 — إضافة/تعديل حساب بنكي. غير المدير العام مثبَّتٌ على فرعه مهما أرسل. */
class SaveBankAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if (! $user->roleName->isSuperAdmin()) {
            $this->merge(['branch_id' => $user->branchId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'iban_last4' => ['nullable', 'digits:4'],
            // تاسك 234: طريقة الدفع المقابلة في تقرير المبيعات — عامة أو من فرع الحساب نفسه.
            'payment_method_id' => [
                'nullable', 'integer',
                Rule::exists('payment_methods', 'id')->where(fn ($q) => $q->where(
                    fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $this->input('branch_id')),
                )),
            ],
        ];
    }
}
