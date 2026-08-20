<?php

namespace App\Policies;

use App\Models\CatalogCategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 47: each branch builds its own catalogue tree. The super admin owns the
 * general rows every branch inherits; the branch admin owns the rows they
 * created and may not touch a general row — editing or deleting one would
 * change every other branch's catalogue.
 */
class CatalogCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, CatalogCategory $category): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $category->branch_id === null)
            || $this->ownsBranchRow($user, $category);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CatalogCategory $category): bool
    {
        return $user->roleName->isSuperAdmin() || $this->ownsBranchRow($user, $category);
    }

    public function delete(User $user, CatalogCategory $category): bool
    {
        return $this->update($user, $category);
    }

    private function ownsBranchRow(User $user, CatalogCategory $category): bool
    {
        return $user->roleName->isBranchAdmin()
            && $category->branch_id !== null
            && $category->branch_id === $user->branch_id;
    }
}
