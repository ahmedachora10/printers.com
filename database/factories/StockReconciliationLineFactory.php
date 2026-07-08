<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockReconciliation;
use App\Models\StockReconciliationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReconciliationLine>
 */
class StockReconciliationLineFactory extends Factory
{
    public function definition(): array
    {
        $systemQty = fake()->numberBetween(0, 50);

        return [
            'reconciliation_id' => StockReconciliation::factory(),
            'product_id' => Product::factory(),
            'system_qty' => $systemQty,
            'physical_qty' => $systemQty,
            'variance' => 0,
            'movement_id' => null,
        ];
    }
}
