<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The subset of branch columns a branch-admin may edit for their own branch
 * from /app-settings. Deliberately omits `owner_id` and `is_active` — those
 * stay super-admin-only on /branches.
 */
class UpdateBranchProfileRequest extends FormRequest
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
            'logo' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
