<?php

namespace App\Actions\Setting;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class UpdateInventoryAlertsAction
{
    public function handle(array $data, ?int $branchId): void
    {
        DB::transaction(function () use ($data, $branchId) {
            if (array_key_exists('min_stock_alert_threshold', $data)) {
                Setting::set('min_stock_alert_threshold', $data['min_stock_alert_threshold'], $branchId);
            }
        });
    }
}
