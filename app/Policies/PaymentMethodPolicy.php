<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 59 — «التحكم في إضافة وحذف طرق الدفع».
 *
 * قاعدة الملكية هي نفسها قاعدة دليل الأسعار (تاسك 47): مدير الفرع يكتب صفوف
 * فرعه وحدها، ولا يمسّ صفّاً عاماً — تعديله يغيّر منتقي الدفع في كل الفروع —
 * ولا صفّ فرع آخر. والسوبر أدمن يملك الكل.
 */
class PaymentMethodPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        // مدير الفرع يضيف لفرعه؛ `branch_id` يُثبَّت على الخادم في الـForm Request
        // فلا يُلتفّ عليه بطلب مباشر.
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, PaymentMethod $paymentMethod): bool
    {
        return $this->owns($user, $paymentMethod);
    }

    public function delete(User $user, PaymentMethod $paymentMethod): bool
    {
        return $this->owns($user, $paymentMethod);
    }

    /** الصفّ العام ملكٌ للسوبر أدمن وحده؛ وصفّ الفرع لمديره. */
    private function owns(User $user, PaymentMethod $paymentMethod): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin()
            && $paymentMethod->branch_id !== null
            && $paymentMethod->branch_id === $user->branchId;
    }
}
