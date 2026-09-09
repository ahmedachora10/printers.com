<?php

namespace App\Http\Requests\DeliveryProvider;

use App\Enums\DeliveryProviderTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * الفرع يُثبَّت من المستخدم لا من الطلب: مدير الفرع يكتب لفرعه وحده مهما
     * أرسل. والسوبر أدمن وحده يختار الفرع — العمود إلزاميّ ولا فرع له هو.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if (! $user?->roleName->isSuperAdmin()) {
            $this->merge(['branch_id' => $user?->branchId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('delivery_providers', 'name')
                    ->whereNull('deleted_at')
                    ->where('branch_id', $this->input('branch_id')),
            ],
            'type' => ['required', Rule::enum(DeliveryProviderTypeEnum::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'اختر الفرع.',
            'name.required' => 'اسم السائق أو شركة التوصيل مطلوب.',
            'name.unique' => 'يوجد مزوّد توصيل بهذا الاسم في هذا الفرع بالفعل.',
            'type.required' => 'اختر النوع: سائق أم شركة توصيل.',
        ];
    }
}
