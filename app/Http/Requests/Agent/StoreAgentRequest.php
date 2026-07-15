<?php

namespace App\Http\Requests\Agent;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Enums\AgentTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $user = Auth::user();

        // Non super-admins always create agents under their own branch;
        // only super-admin chooses the branch from the form.
        if (! $user->roleName?->isSuperAdmin()) {
            $this->merge(['branch_id' => $user->branchId]);
        }

        // Default the discount type so existing callers (and the rate cap) keep
        // treating the rate as a percentage unless a fixed amount is chosen.
        $this->merge(['discount_type' => $this->input('discount_type', 'percentage')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')->whereNull('deleted_at'),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],

            // Agent profile fields.
            'agent_type' => ['required', new Enum(AgentTypeEnum::class)],
            'discount_mode' => ['required', new Enum(AgentDiscountModeEnum::class)],
            'discount_type' => ['required', new Enum(AgentDiscountTypeEnum::class)],
            // Percentage rates are capped at 100; fixed SAR amounts are not.
            'rate' => ['required', 'numeric', 'min:0', Rule::when($this->input('discount_type') !== 'fixed', ['max:100'])],
            'commercial_reg_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
