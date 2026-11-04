<?php

namespace App\Http\Requests\Customer;

use App\Enums\CustomerTypeEnum;
use App\Models\Customer;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => Customer::normalizePhone($this->input('phone'))]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', new PhoneNumber, Customer::uniquePhoneRule($this->route('customer'))],
            'email' => ['nullable', 'email', 'max:255'],
            'customer_type' => ['required', Rule::enum(CustomerTypeEnum::class)],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:customer_type,corporate'],
            'tax_number' => ['nullable', 'string', 'digits:15'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
