<?php

namespace App\Policies;

use App\Models\CatalogSubcategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CatalogSubcategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function view(User $user, CatalogSubcategory $subcategory): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function update(User $user, CatalogSubcategory $subcategory): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function delete(User $user, CatalogSubcategory $subcategory): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
