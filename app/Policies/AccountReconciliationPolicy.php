<?php

namespace App\Policies;

use App\Models\AccountReconciliation;
use App\Models\User;

/**
 * تاسك 121 — المحاسب يُدخل ويحفظ في فرعه؛ مدير الفرع (فرعه) والسوبر أدمن
 * يعتمدان ويلغيان الاعتماد. نفس نمط المصروفات (تاسك 113).
 */
class AccountReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function approve(User $user, AccountReconciliation $reconciliation): bool
    {
        return $this->approveAny($user) && $this->inBranch($user, $reconciliation) && ! $reconciliation->isApproved();
    }

    public function unapprove(User $user, AccountReconciliation $reconciliation): bool
    {
        return $this->approveAny($user) && $this->inBranch($user, $reconciliation) && $reconciliation->isApproved();
    }

    public function approveAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    private function inBranch(User $user, AccountReconciliation $reconciliation): bool
    {
        return $user->roleName->isSuperAdmin() || $user->branchId === $reconciliation->branch_id;
    }
}
