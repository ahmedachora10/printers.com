<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use App\Models\City;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $cities = City::all();

        foreach ($cities as $city) {
            Branch::factory()->create([
                'city_id' => $city->id,
                'name' => 'فرع ' . $city->name,
            ]);
        }
    }
}
