<?php

namespace App\Policies;

use App\Models\DeliveryZone;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 93 — شرائح التوصيل: قاعدة الملكية نفسها التي تحكم مزوّدي التوصيل.
 */
class DeliveryZonePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, DeliveryZone $zone): bool
    {
        return $this->owns($user, $zone);
    }

    public function delete(User $user, DeliveryZone $zone): bool
    {
        return $this->owns($user, $zone);
    }

    private function owns(User $user, DeliveryZone $zone): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin()
            && $user->branchId !== null
            && $zone->branch_id === $user->branchId;
    }
}
