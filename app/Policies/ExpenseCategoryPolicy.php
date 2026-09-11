<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 102 — قاعدة ملكية طرق الدفع (تاسك 59): مدير الفرع يكتب فئات فرعه وحدها،
 * والفئة العامة — يراها كل فرع — للسوبر أدمن وحده.
 */
class ExpenseCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        // مدير بلا فرع كان سيكتب فئةً عامة؛ `branch_id` يُثبَّت في الـForm Request.
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $this->owns($user, $expenseCategory);
    }

    public function delete(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $this->owns($user, $expenseCategory);
    }

    private function owns(User $user, ExpenseCategory $expenseCategory): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin()
            && $expenseCategory->branch_id !== null
            && $expenseCategory->branch_id === $user->branchId;
    }
}
