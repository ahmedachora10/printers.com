<?php

namespace App\Policies;

use App\Models\ServiceTemplate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServiceTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function update(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function delete(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function restore(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, ServiceTemplate $serviceTemplate): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
