<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $primary = $this->route('customer');

        return [
            'secondary_customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
                Rule::notIn([$primary?->id]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'secondary_customer_id.not_in' => 'لا يمكن دمج العميل مع نفسه.',
        ];
    }
}
