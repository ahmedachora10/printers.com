<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id'       => Branch::factory(),
            'category_id'     => ProductCategory::factory(),
            'unit_id'         => ProductUnit::factory(),
            'sku'             => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'name'            => fake()->words(3, true),
            'cost_price'      => fake()->randomFloat(2, 1, 500),
            'selling_price'   => fake()->randomFloat(2, 5, 1000),
            'min_stock_level' => fake()->numberBetween(0, 20),
            'current_stock'   => fake()->numberBetween(0, 100),
            'barcode'         => fake()->optional()->ean13(),
            'is_active'       => true,
        ];
    }
}
