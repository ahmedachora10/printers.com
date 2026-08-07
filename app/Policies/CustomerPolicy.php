<?php

namespace App\Policies;

use App\Enums\CustomerTypeEnum;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant()
            || $user->roleName->isEmployee();
    }

    public function view(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id;
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant()
            || $user->roleName->isEmployee();
    }

    public function update(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id
            && ($user->roleName->isBranchAdmin() || $user->roleName->isAccountant() || $user->roleName->isEmployee());
    }

    /**
     * Correcting a customer from an invoice screen. On top of the roles that may
     * edit customers outright, the employee raising the invoice may fix a plain
     * individual walk-in record. A corporate customer or one owned by an agent
     * carries billing arrangements beyond the counter, so it stays with the
     * accountant.
     */
    public function updateFromInvoice(User $user, Customer $customer): bool
    {
        if ($this->update($user, $customer)) {
            return true;
        }

        return $user->roleName->isEmployee()
            && $user->branchId === $customer->branch_id
            && $customer->customer_type === CustomerTypeEnum::Individual
            && $customer->agent_id === null;
    }

    public function delete(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id
            && ($user->roleName->isBranchAdmin() || $user->roleName->isAccountant());
    }

    public function merge(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->branchId === $customer->branch_id
            && ($user->roleName->isBranchAdmin() || $user->roleName->isAccountant());
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}