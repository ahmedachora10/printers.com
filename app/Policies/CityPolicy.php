<?php

namespace App\Policies;

use App\Models\City;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function view(User $user, City $city): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function update(User $user, City $city): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function delete(User $user, City $city): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function restore(User $user, City $city): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, City $city): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
