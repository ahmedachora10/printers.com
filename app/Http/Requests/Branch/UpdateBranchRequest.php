<?php

namespace App\Http\Requests\Branch;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'city_id' => ['sometimes', 'exists:cities,id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'business_type' => ['nullable', 'string', 'max:255'],
            'commercial_reg_no' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'vat_rate_override' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'owner_id' => [
                'nullable',
                'exists:users,id',
                Rule::unique('branches', 'owner_id')->ignore($this->route('branch')),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value && ! User::find($value)?->hasRole('branch-admin')) {
                        $fail('يجب أن يكون المالك مديراً للفرع (branch-admin).');
                    }
                },
            ],
            // تاسك 228: المسار للسوبر أدمن وحده (role:super-admin) — مدير الفرع يعدّل
            // فرعه من UpdateBranchProfileRequest الذي لا يحمل هذه الحقول.
            'subscription_starts_at' => ['nullable', 'date', 'required_with:subscription_ends_at'],
            'subscription_ends_at' => ['nullable', 'date', 'after:subscription_starts_at'],
            'subscription_grace_until' => ['nullable', 'date', 'after:subscription_ends_at'],
            'is_active' => ['boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'owner_id.unique' => 'هذا المستخدم مسجل كمالك لفرع آخر.',
            'subscription_starts_at.required_with' => 'حدّد تاريخ بداية الاشتراك.',
            'subscription_ends_at.after' => 'نهاية الاشتراك يجب أن تكون بعد بدايته.',
            'subscription_grace_until.after' => 'المهلة يجب أن تنتهي بعد نهاية الاشتراك.',
        ];
    }
}
