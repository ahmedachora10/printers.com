<?php

namespace App\Policies;

use App\Models\CatalogPrice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CatalogPricePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function view(User $user, CatalogPrice $price): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function update(User $user, CatalogPrice $price): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function delete(User $user, CatalogPrice $price): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
