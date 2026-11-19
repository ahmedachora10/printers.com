<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Database\Factories\Concerns\ForBranches;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    use ForBranches;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
