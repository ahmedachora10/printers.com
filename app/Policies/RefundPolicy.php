<?php

namespace App\Policies;

use App\Enums\InvoiceStatusEnum;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RefundPolicy
{
    use HandlesAuthorization;

    /**
     * Refunds are handled by branch management and accountants
     * (holders of the `process-refund` permission).
     */
    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->viewAny($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $refund->branch_id);
    }

    /**
     * إنشاء مرتجع. المحاسب ممنوع منه **بعد اعتماد الفاتورة** (تاسك 42): بمجرد أن
     * تصير الفاتورة مدفوعة يعود ردّ المال قراراً إدارياً لمدير الفرع، ويبقى
     * للموظف صاحبها زرّ «استرجاع الفاتورة» المستقل (ServiceInvoicePolicy::
     * returnInvoice) الذي لم يُمَس.
     *
     * تُستدعى بلا فاتورة من شاشة /refunds العامة، فيُسمح بالدخول ثم تُفحص كل
     * فاتورة على حدة عند الحفظ — لذلك $invoice اختيارية.
     */
    public function create(User $user, ProductInvoice|ServiceInvoice|null $invoice = null): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($invoice === null || ! $user->roleName->isAccountant()) {
            return true;
        }

        return $invoice->status !== InvoiceStatusEnum::PAID;
    }
}
