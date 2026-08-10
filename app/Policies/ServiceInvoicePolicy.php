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
     * Recording a deposit or an instalment is the same authority as settling the
     * invoice outright — an accountant or branch admin in the invoice's branch —
     * and only while the invoice still awaits money.
     */
    public function recordPayment(User $user, ServiceInvoice $invoice): bool
    {
        return $this->updateStatus($user, $invoice) && $invoice->status->acceptsPayment();
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
     * Who may stamp "تم تسليم العمل" (تاسك 31): whoever hands the finished work
     * over the counter — the employee who raised the invoice, or a branch admin
     * or accountant in its branch. An already-delivered, cancelled or returned
     * invoice has nothing left to deliver, so the control disappears with it.
     */
    public function deliver(User $user, ServiceInvoice $invoice): bool
    {
        $isOwnerEmployee = $user->roleName->isEmployee() && $user->id === $invoice->user_id;

        $isReviewer = ($user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant())
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id);

        return ($isOwnerEmployee || $isReviewer)
            && $invoice->delivered_at === null
            && $invoice->status !== InvoiceStatusEnum::CANCELLED
            && $invoice->status !== InvoiceStatusEnum::RETURNED;
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
