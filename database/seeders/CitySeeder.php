<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            'الرياض',
            'جدة',
            'مكة المكرمة',
            'المدينة المنورة',
            'الدمام',
            'الخبر',
            'الظهران',
            'تبوك',
            'أبها',
            'نجران',
            'جازان',
            'القصيم',
            'حائل',
            'الجوف',
            'عرعر',
        ];

        foreach ($cities as $city) {
            City::firstOrCreate(['name' => $city], ['is_active' => true]);
        }
    }
}
