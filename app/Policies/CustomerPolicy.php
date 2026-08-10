<?php

namespace App\Policies;

use App\Enums\CustomerTypeEnum;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * صلاحيات سجلّ العملاء.
 *
 * المحاسب **خارج** هذا السجلّ بقرار العميل (تاسك 40): إدارة بيانات العملاء لمدير
 * الفرع وحده. ما يبقى للمحاسب هو ما تحتاجه نقطة بيع المنتجات — البحث عن عميل
 * وربطه بفاتورة آجلة — وذلك يمرّ من مسارات نقطة البيع لا من هذه الصلاحيات، ومن
 * اسم العميل الظاهر على الفاتورة نفسها لأنه يحصّل منها.
 */
class CustomerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isEmployee();
    }

    public function view(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id;
    }

    /**
     * شاشة «أرصدة العملاء» — حاجةُ تحصيلٍ لا حاجةُ إدارة: يقرأها المحاسب وهو
     * يبيع آجلاً في نقطة بيع المنتجات ويلاحق ما على العملاء، وإن كان سجلّ
     * العملاء نفسه خارج نطاقه (تاسك 40).
     */
    public function viewOutstanding(User $user): bool
    {
        return $this->viewAny($user) || $user->roleName->isAccountant();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isEmployee();
    }

    /**
     * تسجيل عميل جديد **من الفاتورة** — لا من سجلّ العملاء. المحاسب يفعلها وهو
     * يراجع فاتورة بلا عميل أو يبيع آجلاً في نقطة البيع؛ المنعُ على الشاشة
     * والتصدير، لا على إتمام بيعةٍ بين يديه.
     */
    public function createFromInvoice(User $user): bool
    {
        return $this->create($user) || $user->roleName->isAccountant();
    }

    public function update(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id
            && ($user->roleName->isBranchAdmin() || $user->roleName->isEmployee());
    }

    /**
     * Correcting a customer from an invoice screen. On top of the roles that may
     * edit customers outright, the employee raising the invoice may fix a plain
     * individual walk-in record, and the accountant may fix the same from the
     * invoice he is settling — he is barred from the customer *screen*, not from
     * correcting a name on a bill he has to collect. A corporate customer or one
     * owned by an agent carries billing arrangements beyond the counter, so it
     * stays with the branch admin.
     */
    public function updateFromInvoice(User $user, Customer $customer): bool
    {
        if ($this->update($user, $customer)) {
            return true;
        }

        return ($user->roleName->isEmployee() || $user->roleName->isAccountant())
            && $user->branchId === $customer->branch_id
            && $customer->customer_type === CustomerTypeEnum::Individual
            && $customer->agent_id === null;
    }

    public function delete(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id
            && $user->roleName->isBranchAdmin();
    }

    public function merge(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id
            && $user->roleName->isBranchAdmin();
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
