<?php

namespace App\Http\Requests\Customer;

use App\Enums\CustomerTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = auth()->user()->roleName->isSuperAdmin()
            ? $this->input('branch_id')
            : auth()->user()->branchId;

        return [
            'branch_id'     => [
                auth()->user()->roleName->isSuperAdmin() ? 'required' : 'nullable',
                'exists:branches,id',
            ],
            'full_name'     => ['required', 'string', 'max:255'],
            'phone'         => [
                'required',
                'string',
                'max:20',
                Rule::unique('customers', 'phone')->where('branch_id', $branchId)->whereNull('deleted_at'),
            ],
            'email'         => ['nullable', 'email', 'max:255'],
            'customer_type' => ['required', Rule::enum(CustomerTypeEnum::class)],
            'company_name'  => ['nullable', 'string', 'max:255', 'required_if:customer_type,corporate'],
            'credit_limit'  => ['nullable', 'numeric', 'min:0'],
            // TODO: check only users that's agents role
            'agent_id'      => ['nullable', 'integer', 'exists:users,id'],
            'notes'         => ['nullable', 'string'],
            'is_active'     => ['boolean'],
        ];
    }
}
