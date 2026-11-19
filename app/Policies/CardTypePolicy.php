<?php

namespace App\Policies;

use App\Models\CardType;
use App\Models\User;

/**
 * تاسك 243 — قاعدة فئات المصروفات: مدير الفرع يضيف أنواع فرعه ويعدّل ما نطاقه فرعه
 * وحده؛ العامة والمشتركة بين فروع للسوبر أدمن.
 */
class CardTypePolicy
{
    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchId !== null);
    }

    public function update(User $user, CardType $cardType): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $cardType->isOwnedBy($user->branchId));
    }

    public function delete(User $user, CardType $cardType): bool
    {
        return $this->update($user, $cardType);
    }
}
