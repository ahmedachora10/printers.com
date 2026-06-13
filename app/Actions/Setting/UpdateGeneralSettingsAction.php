<?php

namespace App\Actions\Setting;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateGeneralSettingsAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, User $user): void
    {
        DB::transaction(function () use ($data, $user) {
            $isSuperAdmin = $user->roleName->isSuperAdmin();
            $branchId = $user->branchId;

            if ($isSuperAdmin) {
                if (array_key_exists('app_name', $data)) {
                    Setting::set('app_name', $data['app_name']);
                }
                if (array_key_exists('default_vat_pct', $data)) {
                    Setting::set('default_vat_pct', $data['default_vat_pct']);
                }
            }

            if ($branchId && array_key_exists('vat_override_pct', $data)) {
                Branch::where('id', $branchId)
                    ->update(['vat_rate_override' => $data['vat_override_pct']]);
            }
        });
    }
}
