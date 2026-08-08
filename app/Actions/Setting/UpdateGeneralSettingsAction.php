<?php

namespace App\Actions\Setting;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateGeneralSettingsAction
{
    /**
     * Global-only settings. The per-branch VAT override used to live here too;
     * it now belongs to the branch-data tab (UpdateBranchProfileAction) so a
     * single endpoint owns `branches.vat_rate_override`.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $user): void
    {
        if (! $user->roleName->isSuperAdmin()) {
            return;
        }

        DB::transaction(function () use ($data) {
            if (array_key_exists('app_name', $data)) {
                Setting::set('app_name', $data['app_name']);
            }
            if (array_key_exists('default_vat_pct', $data)) {
                Setting::set('default_vat_pct', $data['default_vat_pct']);
            }
        });
    }
}
