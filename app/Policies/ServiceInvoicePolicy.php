<?php

namespace App\Policies;

use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServiceInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isEmployee()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, ServiceInvoice $invoice): bool
    {
        return $this->viewAny($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isEmployee();
    }

    /**
     * Who may open the due-invoice review queue and settle/cancel invoices.
     * Employees raise due invoices; an accountant or branch admin reviews them.
     */
    public function review(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function updateStatus(User $user, ServiceInvoice $invoice): bool
    {
        return $this->review($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id);
    }

    /**
     * The employee who raised an invoice may re-edit it only while it is still
     * DUE (before an accountant approves it). After approval it is locked.
     */
    public function update(User $user, ServiceInvoice $invoice): bool
    {
        return $user->roleName->isEmployee()
            && $user->id === $invoice->user_id
            && $invoice->status === InvoiceStatusEnum::DUE;
    }

    /**
     * Who may correct the customer attached to an invoice: a reviewer on any
     * invoice in their scope, or the employee who raised it while it is still
     * DUE. What of the customer record they may actually rewrite is a separate
     * question, answered by CustomerPolicy::updateFromInvoice.
     */
    public function updateCustomer(User $user, ServiceInvoice $invoice): bool
    {
        return $this->updateStatus($user, $invoice) || $this->update($user, $invoice);
    }

    /**
     * The employee who raised an invoice may return it before OR after approval
     * (business rule: an accountant can never unwind an employee's invoice — they
     * cancel a due one or book a refund instead). Cancelled and already-returned
     * invoices are unwound already, so there is nothing left to return.
     */
    public function returnInvoice(User $user, ServiceInvoice $invoice): bool
    {
        return $user->roleName->isEmployee()
            && $user->id === $invoice->user_id
            && $invoice->status !== InvoiceStatusEnum::CANCELLED
            && $invoice->status !== InvoiceStatusEnum::RETURNED;
    }
}
