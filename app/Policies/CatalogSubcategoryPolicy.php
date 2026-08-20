<?php

namespace App\Policies;

use App\Models\CatalogSubcategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * See CatalogCategoryPolicy — same ownership rule one level down (تاسك 47).
 * A branch may hang its own subcategory under a general category; it just may
 * not edit the general subcategories it inherits.
 */
class CatalogSubcategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, CatalogSubcategory $subcategory): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $subcategory->branch_id === null)
            || $this->ownsBranchRow($user, $subcategory);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CatalogSubcategory $subcategory): bool
    {
        return $user->roleName->isSuperAdmin() || $this->ownsBranchRow($user, $subcategory);
    }

    public function delete(User $user, CatalogSubcategory $subcategory): bool
    {
        return $this->update($user, $subcategory);
    }

    private function ownsBranchRow(User $user, CatalogSubcategory $subcategory): bool
    {
        return $user->roleName->isBranchAdmin()
            && $subcategory->branch_id !== null
            && $subcategory->branch_id === $user->branch_id;
    }
}
