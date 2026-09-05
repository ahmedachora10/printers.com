<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    /**
     * Sign in as another user. Reserved for admins, and never targets another
     * admin, a deactivated account, or yourself. Branch-admins are confined to
     * their own branch staff.
     */
    public function impersonate(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        if (! $model->is_active) {
            return false;
        }

        if ($model->hasRole(['super-admin', 'branch-admin'])) {
            return false;
        }

        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    /**
     * تاسك 86: مرفقات ملفّ الموظف — من يديره يراها، والموظف يرى ملفّه هو.
     * والسيرة الذاتية بيانٌ شخصي، فلا يراها زميلٌ في الفرع ولا مديرُ فرعٍ آخر.
     */
    public function viewAttachments(User $user, User $model): bool
    {
        return $user->id === $model->id || $this->manageAttachments($user, $model);
    }

    /** الرفع والحذف للإدارة وحدها: الموظف يقرأ ملفّه ولا يعدّل فيه. */
    public function manageAttachments(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    public function restore(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    /**
     * A branch-admin may manage staff (accountant/employee/agent) within their own branch,
     * but never other super-admins or branch-admins.
     */
    private function managesInBranch(User $user, User $model): bool
    {
        if (! $user->roleName->isBranchAdmin()) {
            return false;
        }

        if ($model->hasRole(['super-admin', 'branch-admin'])) {
            return false;
        }

        return $user->branchId !== null && $model->branch_id === $user->branchId;
    }
}
