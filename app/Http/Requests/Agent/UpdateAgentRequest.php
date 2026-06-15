<?php

namespace App\Http\Requests\Agent;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $agent = $this->route('agent');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($agent->id)->whereNull('deleted_at'),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($agent->id)->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            // Optional on update: a blank password leaves the existing one untouched.
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],

            // Agent profile fields.
            'agent_type' => ['required', new Enum(AgentTypeEnum::class)],
            'discount_mode' => ['required', new Enum(AgentDiscountModeEnum::class)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'commercial_reg_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
