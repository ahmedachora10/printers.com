<?php

namespace Database\Factories;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequestLine>
 */
class PurchaseRequestLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'request_id' => PurchaseRequest::factory(),
            'product_id' => null,
            'item_name' => fake()->words(2, true),
            'qty' => fake()->numberBetween(1, 20),
            'estimated_unit_cost' => fake()->randomFloat(2, 5, 500),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
