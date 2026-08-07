<?php

namespace App\Http\Requests\Agent;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Enums\AgentTypeEnum;
use App\Http\Requests\Agent\Concerns\NormalizesAgentBranchTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateAgentRequest extends FormRequest
{
    use NormalizesAgentBranchTerms;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Default the discount type so the rate cap keeps treating the rate as a
        // percentage unless a fixed amount is explicitly chosen.
        $this->merge(['discount_type' => $this->input('discount_type', 'percentage')]);

        $this->normalizeBranchTerms();
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
            'discount_type' => ['required', new Enum(AgentDiscountTypeEnum::class)],
            // Percentage rates are capped at 100; fixed SAR amounts are not.
            'rate' => ['required', 'numeric', 'min:0', Rule::when($this->input('discount_type') !== 'fixed', ['max:100'])],
            'commercial_reg_no' => ['nullable', 'string', 'max:100'],

            // The branches this agent works with, each on its own terms.
            ...$this->branchTermRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateBranchRateCaps($validator));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->branchTermMessages();
    }
}
