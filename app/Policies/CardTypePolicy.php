<?php

namespace App\Policies;

use App\Models\CardType;
use App\Models\User;

/** تاسك 146 — أنواع البطاقات قائمة عامة: المدير العام وحده يعدّلها. */
class CardTypePolicy
{
    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function update(User $user, CardType $cardType): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function delete(User $user, CardType $cardType): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
