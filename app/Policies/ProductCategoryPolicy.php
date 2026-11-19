<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 237 — قاعدة فئات المصروفات: مدير الفرع يضيف لفرعه ويعدّل ما نطاقه فرعه وحده؛
 * العامة والمشتركة بين فروع للسوبر أدمن.
 */
class ProductCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, ProductCategory $productCategory): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        // مدير بلا فرع كان سيكتب فئةً عامة؛ الفروع تُثبَّت في الـForm Request.
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        return $this->owns($user, $productCategory);
    }

    public function delete(User $user, ProductCategory $productCategory): bool
    {
        return $this->owns($user, $productCategory);
    }

    public function restore(User $user, ProductCategory $productCategory): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, ProductCategory $productCategory): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    private function owns(User $user, ProductCategory $productCategory): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $productCategory->isOwnedBy($user->branchId));
    }
}
