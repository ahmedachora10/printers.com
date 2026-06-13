<?php

namespace App\Http\Requests\Branch;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'city_id' => ['required', 'exists:cities,id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'business_type' => ['nullable', 'string', 'max:255'],
            'commercial_reg_no' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'vat_rate_override' => ['required', 'numeric', 'min:0', 'max:100'],
            'owner_id' => [
                'nullable',
                'exists:users,id',
                Rule::unique('branches', 'owner_id'),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value && ! User::find($value)?->hasRole('branch-admin')) {
                        $fail('يجب أن يكون المالك مديراً للفرع (branch-admin).');
                    }
                },
            ],
            'is_active' => ['boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'owner_id.unique' => 'هذا المستخدم مسجل كمالك لفرع آخر.',
        ];
    }
}
