<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `branch_id` يُثبَّت من المستخدم لا من الطلب (تاسك 59): مدير الفرع يكتب
     * لفرعه وحده، والسوبر أدمن يكتب صفّاً عاماً يرثه كل فرع. إرساله في الطلب
     * يُتجاهَل تماماً فلا يُلتفّ على السياسة.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        $this->merge([
            'branch_id' => $user?->roleName->isSuperAdmin() ? null : $user?->branchId,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                // التفرّد داخل النطاق: يجوز أن يسمّي فرعٌ طريقته باسم طريقة فرع
                // آخر، ولا يجوز أن يكرّر اسماً **يراه** (العام أو ما أضافه هو).
                Rule::unique('payment_methods', 'name')
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $this->input('branch_id') === null
                        ? $q->whereNull('branch_id')
                        : $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $this->input('branch_id')))),
            ],
            'is_active' => ['boolean'],
            'requires_attachment' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'يوجد طريقة دفع بهذا الاسم متاحة لفرعك بالفعل.',
        ];
    }
}
