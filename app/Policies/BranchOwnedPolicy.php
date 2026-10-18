<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * صفوفٌ يملكها فرع (أجهزة الشبكة — تاسك 146، الحسابات البنكية — تاسك 187):
 * مدير الفرع يدير صفوف فرعه، والمدير العام صفوف كل الفروع.
 */
class BranchOwnedPolicy
{
    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId === $model->branch_id);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
