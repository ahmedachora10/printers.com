<?php

namespace App\Policies;

use App\Enums\InvoiceStatusEnum;
use App\Models\ProductInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, ProductInvoice $invoice): bool
    {
        return $this->viewAny($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    /**
     * Recording a deposit or an instalment on a product invoice: whoever may
     * raise one in this branch, and only while the invoice still awaits money.
     */
    public function recordPayment(User $user, ProductInvoice $invoice): bool
    {
        return $this->create($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id)
            && $invoice->status->acceptsPayment();
    }

    /**
     * تعديل الفاتورة كاملةً — لمدير الفرع في فرعه ولمدير النظام، آجلةً كانت أو
     * مدفوعة. لا الملغاة ولا المرتجعة، ولا ما عليها مرتجعٌ جزئي (كميات المخزون
     * المُعادة ومبالغه لا تُطابَق مع تعديلٍ لاحق)، ولا ما دخل عائدُ مندوبها دفعةً.
     */
    public function update(User $user, ProductInvoice $invoice): bool
    {
        return ($user->roleName->isSuperAdmin()
                || ($user->roleName->isBranchAdmin() && $user->branchId === $invoice->branch_id))
            && ! in_array($invoice->status->value, InvoiceStatusEnum::excludedFromRevenue(), true)
            && $invoice->agent_payment_id === null
            && ! $invoice->refunds()->exists();
    }

    /**
     * تاسك 99 — تغيير طريقة الدفع بعد البيع: من يصدر فاتورة المنتجات في فرعها،
     * ما لم تكن ملغاة أو مرتجعة.
     */
    public function changePaymentMethod(User $user, ProductInvoice $invoice): bool
    {
        return $this->create($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id)
            && ! in_array($invoice->status->value, InvoiceStatusEnum::excludedFromRevenue(), true);
    }
}
