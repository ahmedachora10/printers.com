<?php

namespace Database\Factories;

use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'branch_id' => Branch::factory(),
            'type' => StockMovementTypeEnum::OPENING_STOCK,
            'qty' => fake()->numberBetween(1, 100),
            'unit_cost' => fake()->randomFloat(2, 1, 500),
            'created_by' => User::factory(),
        ];
    }
}
