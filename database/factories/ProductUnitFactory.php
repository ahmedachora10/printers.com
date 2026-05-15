<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductUnit>
 */
class ProductUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['قطعة', 'علبة', 'كرتون', 'رزمة', 'لتر', 'كيلوغرام', 'غرام', 'متر']),
        ];
    }
}
