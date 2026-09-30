<?php

namespace App\Policies;

use App\Models\NetworkDevice;
use App\Models\User;

/** تاسك 146 — مدير الفرع يدير أجهزة فرعه، والمدير العام أجهزة كل الفروع. */
class NetworkDevicePolicy
{
    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, NetworkDevice $device): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId === $device->branch_id);
    }

    public function delete(User $user, NetworkDevice $device): bool
    {
        return $this->update($user, $device);
    }
}
