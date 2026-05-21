<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentMethodPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function update(User $user, PaymentMethod $paymentMethod): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function delete(User $user, PaymentMethod $paymentMethod): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
