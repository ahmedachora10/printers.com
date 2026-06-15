<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->company(),
            'phone' => fake()->numerify('05########'),
            'email' => fake()->optional()->companyEmail(),
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
