<?php

namespace App\Http\Requests\User;

use App\Enums\Roles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'base_commission_pct' => ['nullable', 'numeric', 'between:0,100'],
            'referral_commission_pct' => ['nullable', 'numeric', 'between:0,100'],
            'joined_date' => ['nullable', 'date'],
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
            : [Roles::ACCOUNTANT->value, Roles::EMPLOYEE->value, Roles::AGENT->value];
    }
}