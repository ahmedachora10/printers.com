<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserServiceCommissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->route('user')->branch_id;

        return [
            'commissions' => ['present', 'array'],
            'commissions.*.branch_service_id' => [
                'required',
                Rule::exists('branch_services', 'id')->where('branch_id', $branchId),
            ],
            'commissions.*.commission_pct' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
