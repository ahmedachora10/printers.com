<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\User;

/** تاسك 187 — مدير الفرع يدير حسابات فرعه البنكية، والمدير العام حسابات كل الفروع. */
class BankAccountPolicy
{
    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, BankAccount $account): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId === $account->branch_id);
    }

    public function delete(User $user, BankAccount $account): bool
    {
        return $this->update($user, $account);
    }
}
