<?php

namespace App\Actions\Setting;

use App\Models\Setting;

class UpdateBranchPaymentMethodsAction
{
    /** @param array<int, int> $enabledIds */
    public function handle(array $enabledIds, int $branchId): void
    {
        Setting::set('enabled_payment_methods', json_encode(array_values($enabledIds)), $branchId);
    }
}
