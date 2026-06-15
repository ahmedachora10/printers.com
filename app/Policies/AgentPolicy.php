<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AgentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, User $agent): bool
    {
        return $this->viewAny($user) && $this->sharesBranch($user, $agent);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $agent): bool
    {
        return $this->viewAny($user) && $this->sharesBranch($user, $agent);
    }

    public function delete(User $user, User $agent): bool
    {
        return $this->update($user, $agent);
    }

    /**
     * Whether the actor may settle rebate payments for this agent.
     */
    public function pay(User $user, User $agent): bool
    {
        return ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin())
            && $this->sharesBranch($user, $agent);
    }

    public function restore(User $user, User $agent): bool
    {
        return ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin())
            && $this->sharesBranch($user, $agent);
    }

    public function forceDelete(User $user, User $agent): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    private function sharesBranch(User $user, User $agent): bool
    {
        return $user->roleName->isSuperAdmin() || $user->branchId === $agent->branch_id;
    }
}
