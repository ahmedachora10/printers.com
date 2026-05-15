<?php

namespace Database\Seeders;

use App\Models\ProductUnit;
use Illuminate\Database\Seeder;

class ProductUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = ['قطعة', 'علبة', 'كرتون', 'رزمة', 'لتر', 'كيلوغرام', 'غرام', 'متر'];

        foreach ($units as $unit) {
            ProductUnit::firstOrCreate(['name' => $unit]);
        }
    }
}
