<?php

namespace App\Policies;

use App\Enums\InvoiceStatusEnum;
use App\Models\InvoiceMessage;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\DB;

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
     * تاسك 99 — تغيير طريقة الدفع، قبل الاعتماد وبعده: خطأ الكاشير في الطريقة
     * يُكتشف غالباً بعد إقفال الفاتورة. نفس سلطة الاعتماد، ولا يمسّ الملغاة ولا
     * المرتجعة — قصّتهما أُغلقت.
     */
    public function changePaymentMethod(User $user, ServiceInvoice $invoice): bool
    {
        return $this->updateStatus($user, $invoice)
            && ! in_array($invoice->status->value, InvoiceStatusEnum::excludedFromRevenue(), true);
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
     * Who may re-edit an invoice — its services, quantities, prices and the
     * materials cost — and only while it is still DUE: its owning employee, or
     * a branch admin (or super admin) in its branch correcting it before
     * approval — تاسك 70. The materials cost is the case that needs it: it comes
     * off the employee's own commission base, so تاسك 54 bars the employee from
     * typing it, leaving the branch admin as the only one who can.
     *
     * **The accountant is deliberately out.** He reviews the money, not the
     * work: on the employee's invoice he may correct the customer details and
     * name the payment method — updateCustomer() and updateStatus() below — but
     * a service, a price or a materials cost is not his to move. Letting him
     * edit here would let him rewrite the base of a commission he then approves.
     *
     * The status condition is shared, not repeated: approval writes the
     * immutable commission_ledger, and nothing may move under it.
     */
    public function update(User $user, ServiceInvoice $invoice): bool
    {
        if ($invoice->status !== InvoiceStatusEnum::DUE) {
            return false;
        }

        $isOwnerEmployee = $user->roleName->isEmployee() && $user->id === $invoice->user_id;

        return $isOwnerEmployee
            || ($this->updateStatus($user, $invoice) && ! $user->roleName->isAccountant());
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
     * تاسك 100 — من يرى المحادثة الداخلية: مَن يرى أرقام الفاتورة الداخلية
     * (المراجعون وصاحبها، قاعدة InvoiceResource::showsInternalCostsTo)، ومعهم
     * موظفٌ أُشير إليه في الخيط فصار مشاركاً. المندوب خارج `view` أصلاً.
     */
    public function viewMessages(User $user, ServiceInvoice $invoice): bool
    {
        if (! $this->view($user, $invoice)) {
            return false;
        }

        return $this->review($user)
            || $user->id === $invoice->user_id
            || DB::table('invoice_thread_participants')->where(['user_id' => $user->id, 'service_invoice_id' => $invoice->id])->exists();
    }

    /** المحادثة المغلقة تُقرأ ولا يُكتب فيها حتى يفتحها مدير الفرع. */
    public function postMessage(User $user, ServiceInvoice $invoice): bool
    {
        return $invoice->messages_closed_at === null && $this->viewMessages($user, $invoice);
    }

    /** تعديل الرسائل وحذفها وإغلاق المحادثة: مدير فرع الفاتورة والسوبر أدمن. */
    public function moderateMessages(User $user, ServiceInvoice $invoice): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId === $invoice->branch_id);
    }

    /** «تم الإجراء» يضعها من يرى الخيط على رسالةِ غيره — لا المرسل على رسالته. */
    public function actionMessage(User $user, ServiceInvoice $invoice, InvoiceMessage $message): bool
    {
        return $message->user_id !== $user->id && $this->viewMessages($user, $invoice);
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
