<?php

namespace App\Policies;

use App\Models\BranchService;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BranchServicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, BranchService $branchService): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->id === $branchService->branch->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, BranchService $branchService): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->id === $branchService->branch->owner_id);
    }

    public function delete(User $user, BranchService $branchService): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->id === $branchService->branch->owner_id);
    }
}
