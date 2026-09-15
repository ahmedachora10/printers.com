<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PurchaseRequestPolicy
{
    use HandlesAuthorization;

    /** Everyone but the agent portal takes part in the request cycle. */
    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant()
            || $user->roleName->isEmployee();
    }

    /**
     * Admins and the accountant see every request in their scope — the
     * accountant sees without deciding (تاسك 108); an employee only ever sees
     * the requests they raised themselves.
     */
    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        if ($user->roleName->isBranchAdmin() || $user->roleName->isAccountant()) {
            return $user->branchId === $purchaseRequest->branch_id;
        }

        return $user->roleName->isEmployee() && $user->id === $purchaseRequest->requested_by;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Only the branch admin of the request's branch (or a super-admin) decides. */
    public function decide(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId === $purchaseRequest->branch_id);
    }

    public function convert(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->decide($user, $purchaseRequest);
    }
}
