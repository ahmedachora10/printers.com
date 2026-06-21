<?php

namespace App\Http\Requests\BranchService;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeCommissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->route('branchService')->branch_id;

        return [
            'commissions' => ['present', 'array'],
            'commissions.*.user_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at'),
            ],
            'commissions.*.commission_pct' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
