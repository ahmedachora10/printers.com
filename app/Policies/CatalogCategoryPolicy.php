<?php

namespace App\Policies;

use App\Models\CatalogCategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CatalogCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function view(User $user, CatalogCategory $category): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function update(User $user, CatalogCategory $category): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function delete(User $user, CatalogCategory $category): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
