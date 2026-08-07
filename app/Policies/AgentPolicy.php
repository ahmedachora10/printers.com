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

    /**
     * An agent may be linked to several branches, so the actor shares a branch
     * with them when their own branch is among those links — not merely when it
     * matches the agent's primary `branch_id`.
     */
    private function sharesBranch(User $user, User $agent): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        $branchId = $user->branchId;

        return $branchId !== null && $agent->termsForBranch($branchId) !== null;
    }
}
