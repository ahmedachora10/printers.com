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
     * تاسك 99 — تغيير طريقة الدفع بعد البيع: من يصدر فاتورة المنتجات في فرعها،
     * ما لم تكن ملغاة أو مرتجعة.
     */
    public function changePaymentMethod(User $user, ProductInvoice $invoice): bool
    {
        return $this->create($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id)
            && ! in_array($invoice->status, [InvoiceStatusEnum::CANCELLED, InvoiceStatusEnum::RETURNED], true);
    }
}
