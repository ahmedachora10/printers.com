<?php

namespace App\Http\Requests\BranchService;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_template_id'  => ['required', 'exists:service_templates,id'],
            'branch_id'            => [
                'required',
                'exists:branches,id',
                Rule::unique('branch_services', 'branch_id')
                    ->where('service_template_id', $this->input('service_template_id')),
            ],
            'base_commission_pct'  => ['required', 'numeric', 'min:0', 'max:100'],
            'max_discount_pct'     => ['required', 'numeric', 'min:0', 'max:100'],
            'is_tahazir'           => ['boolean'],
            'is_active'            => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.unique' => 'هذا الفرع مرتبط بالفعل بقالب الخدمة هذا.',
        ];
    }
}
