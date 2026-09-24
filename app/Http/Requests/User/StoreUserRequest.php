<?php

namespace App\Http\Requests\User;

use App\Enums\Roles;
use App\Rules\SingleBranchAuditor;
use App\Rules\SingleBranchManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
            'username' => [
                'required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username'),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email'),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in($this->assignableRoles()), new SingleBranchAuditor($this->targetBranchId())],
            'branch_id' => [Rule::requiredIf(fn () => $this->input('role') === Roles::AUDITOR->value && $this->user()->roleName->isSuperAdmin()), 'nullable', 'integer', 'exists:branches,id', new SingleBranchManager($this->input('role'))],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'base_commission_pct' => ['nullable', 'numeric', 'between:0,100'],
            'referral_commission_pct' => ['nullable', 'numeric', 'between:0,100'],
            'joined_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Roles the current actor is allowed to assign.
     *
     * @return list<string>
     */
    protected function assignableRoles(): array
    {
        return $this->user()->roleName->isSuperAdmin()
            ? Roles::all()
            : [Roles::AUDITOR->value, Roles::ACCOUNTANT->value, Roles::EMPLOYEE->value, Roles::AGENT->value];
    }

    /** The branch the user will end up on: a branch admin's own, whatever the form sends. */
    private function targetBranchId(): ?int
    {
        return $this->user()->roleName->isSuperAdmin()
            ? ($this->integer('branch_id') ?: null)
            : $this->user()->branchId;
    }
}
