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
        return $user->hasRole('super-admin') || $user->hasRole('branch-admin');
    }

    public function view(User $user, BranchService $branchService): bool
    {
        return $user->hasRole('super-admin')
            || ($user->hasRole('branch-admin') && $user->branch_id === $branchService->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin') || $user->hasRole('branch-admin');
    }

    public function update(User $user, BranchService $branchService): bool
    {
        return $user->hasRole('super-admin')
            || ($user->hasRole('branch-admin') && $user->branch_id === $branchService->branch_id);
    }

    public function delete(User $user, BranchService $branchService): bool
    {
        return $user->hasRole('super-admin')
            || ($user->hasRole('branch-admin') && $user->branch_id === $branchService->branch_id);
    }
}
