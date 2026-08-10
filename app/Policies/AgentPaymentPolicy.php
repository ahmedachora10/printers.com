<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * شاشة صرف عمولات المناديب. المحاسب مشمول (تاسك 41) — هو من يصرف — ومَن يصرف
 * لأي مندوب بعينه تحسمه AgentPolicy::pay داخل المتحكِّم، لا هذه الصلاحية.
 */
class AgentPaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
