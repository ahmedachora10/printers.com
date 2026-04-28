<?php

namespace App;

enum Roles:string
{
    case SUPER_ADMIN  = 'super-admin';
    case BRANCH_ADMIN = 'branch-admin';
    case ACCOUNTANT   = 'accountant';
    case EMPLOYEE     = 'employee';
    case AGENT        = 'agent';

    public function label(): string
    {
        return match($this) {
            self::SUPER_ADMIN  => 'مدير عام',
            self::BRANCH_ADMIN => 'مدير فرع',
            self::ACCOUNTANT   => 'محاسب',
            self::EMPLOYEE     => 'موظف',
            self::AGENT        => 'وكيل',
        };
    }

    public static function all(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::SUPER_ADMIN;
    }

    public function isBranchAdmin(): bool
    {
        return $this === self::BRANCH_ADMIN;
    }

    public function isAccountant(): bool
    {
        return $this === self::ACCOUNTANT;
    }

    public function isEmployee(): bool
    {
        return $this === self::EMPLOYEE;
    }

    public function isAgent(): bool
    {
        return $this === self::AGENT;
    }
}
