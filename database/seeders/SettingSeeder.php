<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $globals = [
            ['key' => 'app_name',        'value' => 'مركز الناسخ للطباعة'],
            ['key' => 'default_vat_pct', 'value' => '15.00'],
            ['key' => 'session_timeout', 'value' => '120'],
        ];

        foreach ($globals as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key'], 'branch_id' => null],
                ['value' => $setting['value']]
            );
        }
    }
}
