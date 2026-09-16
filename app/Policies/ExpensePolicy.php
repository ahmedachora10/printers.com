<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ExpensePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    /** تاسك 112: المرفق يُفتح بهذه الصلاحية، فلا يصل مستخدمُ فرعٍ آخر إلى مستند فرعٍ غيره. */
    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $expense->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * تاسك 113 — المحاسب يعدّل ويحذف قبل الاعتماد وحده؛ مدير الفرع والسوبر أدمن
     * دائماً (والتعديل مسجَّلٌ في activity_log). وكلاهما في فرع المصروف.
     */
    public function update(User $user, Expense $expense): bool
    {
        // تاسك 111: مصروف تسوية التوصيل يُدار من كشف التوصيل وحده.
        return $expense->delivery_provider_id === null
            && $this->view($user, $expense)
            && ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin() || ! $expense->isApproved());
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->update($user, $expense);
    }

    /** تاسك 113 — الاعتماد لمدير الفرع (فرعه) والسوبر أدمن. */
    public function approve(User $user, Expense $expense): bool
    {
        return $this->approveAny($user) && $this->view($user, $expense) && ! $expense->isApproved();
    }

    public function unapprove(User $user, Expense $expense): bool
    {
        return $expense->delivery_provider_id === null
            && $this->approveAny($user) && $this->view($user, $expense) && $expense->isApproved();
    }

    public function approveAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
