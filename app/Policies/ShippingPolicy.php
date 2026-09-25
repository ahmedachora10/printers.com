<?php

namespace App\Policies;

use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 93 — السائقون وشرائح الأسعار تحكمهما القاعدة نفسها: مدير الفرع يدير ما
 * يخصّ فرعه، والسوبر أدمن يملك الكل. سياسةٌ واحدة مسجَّلةٌ للنموذجين.
 *
 * أبسط من `PaymentMethodPolicy`: لا صفوفَ عامّة تُستثنى هنا، لأن العميل حسم
 * أن لكل فرع قوائمه — فالملكية شرطُ مساواةٍ واحد.
 */
class ShippingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        // `branch_id` يُثبَّت على الخادم في الـForm Request، فمدير الفرع لا
        // يكتب لفرعٍ غيره ولو أرسل معرِّفاً في الطلب.
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, DeliveryProvider|DeliveryZone $model): bool
    {
        return $this->owns($user, $model);
    }

    public function delete(User $user, DeliveryProvider|DeliveryZone $model): bool
    {
        return $this->owns($user, $model);
    }

    /**
     * تاسك 127 — قراءة كشف التوصيل وحدها، والمحاسب فيها. مفصولةٌ عن `viewAny`
     * عمداً: المحاسب يتابع ما على السائقين ولا يضيف سائقاً ولا يسعّر شريحة.
     */
    public function viewDeliveries(User $user): bool
    {
        return $this->viewAny($user)
            || (($user->roleName->isAccountant() || $user->roleName->isAuditor()) && $user->branchId !== null);
    }

    /**
     * تاسك 111 — تسوية أجر السائق وإلغاؤها، في فرع الطلب.
     *
     * تاسك 127: تبقى للإدارة — المحاسب يرى الكشف ولا يسوّي عليه.
     */
    public function settle(User $user, int $branchId): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId === $branchId);
    }

    private function owns(User $user, DeliveryProvider|DeliveryZone $model): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin()
            && $user->branchId !== null
            && $model->branch_id === $user->branchId;
    }
}
