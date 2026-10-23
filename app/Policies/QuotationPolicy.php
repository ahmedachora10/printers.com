<?php

namespace App\Policies;

use App\Enums\InvoiceTypeEnum;
use App\Enums\QuotationStatusEnum;
use App\Models\Quotation;
use App\Models\User;

/**
 * عروض الأسعار: من يملك نقطة بيع النوع يُنشئ عرضه ويحوّله، والموظف يرى
 * عروضه وحده. لا اعتماد داخلي — «معتمد/مرفوض» قرار العميل يسجّله صاحب
 * العرض أو الإدارة.
 */
class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant()
            || $user->roleName->isEmployee()
            || $user->roleName->isAuditor();
    }

    public function view(User $user, Quotation $quotation): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $quotation->branch_id
            && (! $user->roleName->isEmployee() || $user->id === $quotation->user_id);
    }

    /** إنشاء عرضٍ من نوعٍ ما = صلاحية إنشاء فاتورته. */
    public function create(User $user, InvoiceTypeEnum $type): bool
    {
        return $user->can('create', $type->modelClass());
    }

    /** صاحب العرض قبل ردّ العميل، والإدارة في أي حالة ما لم يُحوَّل. */
    public function update(User $user, Quotation $quotation): bool
    {
        if (! $this->view($user, $quotation) || $quotation->status === QuotationStatusEnum::CONVERTED) {
            return false;
        }

        if ($this->isManager($user)) {
            return true;
        }

        return $this->isOwner($user, $quotation)
            && in_array($quotation->status, [QuotationStatusEnum::NEW, QuotationStatusEnum::PENDING], true);
    }

    /** تسجيل الإرسال للعميل وردّه (قبول/رفض). */
    public function changeStatus(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation)
            && $quotation->status !== QuotationStatusEnum::CONVERTED
            && ($this->isOwner($user, $quotation) || $this->isManager($user));
    }

    public function print(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation) && ! $user->roleName->isAuditor();
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return $quotation->status === QuotationStatusEnum::APPROVED
            && $this->view($user, $quotation)
            && $this->create($user, $quotation->type);
    }

    private function isOwner(User $user, Quotation $quotation): bool
    {
        return $user->id === $quotation->user_id;
    }

    private function isManager(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }
}
