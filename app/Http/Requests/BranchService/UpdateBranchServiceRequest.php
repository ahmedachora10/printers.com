<?php

namespace App\Http\Requests\BranchService;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_commission_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_discount_pct'    => ['required', 'numeric', 'min:0', 'max:100'],
            'is_tahazir'          => ['boolean'],
            'is_active'           => ['boolean'],
        ];
    }
}
