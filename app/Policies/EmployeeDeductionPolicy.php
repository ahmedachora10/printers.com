<?php

namespace App\Policies;

use App\Models\EmployeeDeduction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 74: «للإدارة صلاحية تطبيق الخصم» — أي السوبر أدمن ومدير الفرع وحدهما،
 * ومدير الفرع داخل فرعه فقط. ولا `update` هنا: القيد لا يُعاد كتابته بعد الإدراج.
 * أمّا `delete` فمتاحٌ لمن يملك التطبيق نفسه — الحذف soft، فيبقى الأثر.
 */
class EmployeeDeductionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** الموظف المحسوم عليه لا بدّ أن يكون في فرع المطبِّق — إلا للسوبر أدمن. */
    public function applyTo(User $user, User $employee): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        $employeeBranchId = $employee->getAttributes()['branch_id'] ?? null;

        return $user->roleName->isBranchAdmin()
            && $employeeBranchId !== null
            && (int) $employeeBranchId === $user->branchId;
    }

    public function view(User $user, EmployeeDeduction $deduction): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $deduction->branch_id === $user->branchId);
    }

    /**
     * ملاحظات العميل: «إمكانية حذف الخصومات». من يملك تطبيق الحسم يملك إلغاءه،
     * وفي فرعه وحده — تماماً كشرط `applyTo`.
     */
    public function delete(User $user, EmployeeDeduction $deduction): bool
    {
        return $this->view($user, $deduction);
    }
}
