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
