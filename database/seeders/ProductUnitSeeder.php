<?php

namespace Database\Seeders;

use App\Models\ProductUnit;
use Illuminate\Database\Seeder;

class ProductUnitSeeder extends Seeder
{
    public function run(): void
    {
        // «متر مربع» للمنتجات المسعّرة بالمساحة (تاسك 51) — وهي التي تحمل is_sqm.
        $units = ['قطعة', 'علبة', 'كرتون', 'رزمة', 'لتر', 'كيلوغرام', 'غرام', 'متر', 'متر مربع'];

        foreach ($units as $unit) {
            ProductUnit::firstOrCreate(['name' => $unit]);
        }
    }
}
