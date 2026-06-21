<?php

namespace App\Rules;

use App\Enums\Roles;
use App\Models\Branch;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A branch may have only one manager: reject a branch already owned by
 * another user when the BRANCH_ADMIN role is being assigned. The user
 * being updated may keep the branch they already manage (pass their id
 * as $ignoreUserId).
 */
class SingleBranchManager implements ValidationRule
{
    public function __construct(
        private ?string $role,
        private ?int $ignoreUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->role !== Roles::BRANCH_ADMIN->value || ! $value) {
            return;
        }

        $managedByAnother = Branch::query()
            ->whereKey($value)
            ->whereNotNull('owner_id')
            ->when($this->ignoreUserId, fn ($q) => $q->where('owner_id', '!=', $this->ignoreUserId))
            ->exists();

        if ($managedByAnother) {
            $fail('هذا الفرع لديه مدير بالفعل.');
        }
    }
}
