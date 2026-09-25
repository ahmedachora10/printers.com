<?php

namespace App\Http\Requests\User;

use App\Enums\Roles;
use App\Rules\SingleBranchManager;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Same assignable roles as creating a user; only the rules differ. */
class UpdateUserRequest extends StoreUserRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in($this->assignableRoles())],
            'branch_id' => [Rule::requiredIf(fn () => $this->input('role') === Roles::AUDITOR->value && $this->user()->roleName->isSuperAdmin()), 'nullable', 'integer', 'exists:branches,id', new SingleBranchManager($this->input('role'), $userId)],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'base_commission_pct' => ['nullable', 'numeric', 'between:0,100'],
            'referral_commission_pct' => ['nullable', 'numeric', 'between:0,100'],
            'joined_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ];
    }
}
