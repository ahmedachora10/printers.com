<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BranchPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->roleName->isSuperAdmin() || ($user->roleName->isBranchAdmin() && $user->id === $branch->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->roleName->isSuperAdmin() || ($user->roleName->isBranchAdmin() && $user->id === $branch->owner_id);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->hasRole('super-admin');
    }

    public function restore(User $user, Branch $branch): bool
    {
        return $user->hasRole('super-admin');
    }

    public function forceDelete(User $user, Branch $branch): bool
    {
        return $user->hasRole('super-admin');
    }
}
