<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255'],
            'city_id'           => ['required', 'exists:cities,id'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'address'           => ['nullable', 'string'],
            'business_type'     => ['nullable', 'string', 'max:255'],
            'commercial_reg_no' => ['nullable', 'string', 'max:100'],
            'tax_number'        => ['nullable', 'string', 'max:100'],
            'vat_rate_override' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active'         => ['boolean'],
            'logo'              => ['nullable', 'image', 'max:2048'],
        ];
    }
}
