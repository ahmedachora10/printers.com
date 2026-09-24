<?php

namespace App\Rules;

use App\Enums\Roles;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * تاسك 125 — لكل فرع مراجع حسابات واحد. يُعلَّق على حقل `role` لا `branch_id`:
 * مدير الفرع لا يرسل فرعاً أصلاً (يُفرض فرعه في الـAction)، فيُمرَّر هنا الفرعُ
 * الفعلي الذي سيُكتب. والمستخدم المُعدَّل يحتفظ بفرعه ($ignoreUserId).
 */
class SingleBranchAuditor implements ValidationRule
{
    public function __construct(
        private ?int $branchId,
        private ?int $ignoreUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== Roles::AUDITOR->value || ! $this->branchId) {
            return;
        }

        $taken = User::query()
            ->whereHasRole(Roles::AUDITOR->value)
            ->where('branch_id', $this->branchId)
            ->when($this->ignoreUserId, fn ($q) => $q->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail('هذا الفرع لديه مراجع حسابات بالفعل.');
        }
    }
}
