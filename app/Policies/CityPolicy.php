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
        return $user->hasRole('super-admin');
    }

    public function view(User $user, City $city): bool
    {
        return $user->hasRole('super-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, City $city): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user, City $city): bool
    {
        return $user->hasRole('super-admin');
    }

    public function restore(User $user, City $city): bool
    {
        return $user->hasRole('super-admin');
    }

    public function forceDelete(User $user, City $city): bool
    {
        return $user->hasRole('super-admin');
    }
}
