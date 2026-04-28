<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'              => fake()->company(),
            'city_id'           => City::factory(),
            'phone'             => fake()->numerify('05########'),
            'address'           => fake()->address(),
            'business_type'     => fake()->randomElement(['طباعة', 'تصميم', 'إعلانات', 'لافتات']),
            'commercial_reg_no' => fake()->numerify('##########'),
            'tax_number'        => fake()->numerify('3##############'),
            'vat_rate_override' => 15.00,
            'is_active'         => true,
        ];
    }
}
