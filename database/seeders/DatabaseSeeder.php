<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\BranchSeeder;
use Database\Seeders\ServiceTemplateSeeder;
use Database\Seeders\ProductUnitSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\PaymentMethodSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            RolesAndPermissionsSeeder::class,
            CitySeeder::class,
            BranchSeeder::class,
            UserSeeder::class,
            ServiceTemplateSeeder::class,

            // Products
            ProductUnitSeeder::class,
            // ProductSeeder::class,

            // Settings & Payment Methods
            SettingSeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
