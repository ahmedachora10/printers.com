<?php

namespace App\Policies;

use App\Models\DeliveryProvider;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 93 — مدير الفرع يدير سائقي فرعه وحدهم، والسوبر أدمن يملك الكل.
 *
 * أبسط من `PaymentMethodPolicy`: لا صفوفَ عامّة تُستثنى هنا، لأن العميل حسم
 * أن لكل فرع قوائمه — فالملكية شرطُ مساواةٍ واحد.
 */
class DeliveryProviderPolicy
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

    public function update(User $user, DeliveryProvider $provider): bool
    {
        return $this->owns($user, $provider);
    }

    public function delete(User $user, DeliveryProvider $provider): bool
    {
        return $this->owns($user, $provider);
    }

    private function owns(User $user, DeliveryProvider $provider): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin()
            && $user->branchId !== null
            && $provider->branch_id === $user->branchId;
    }
}
