<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    public function definition(): array
    {
        $orderedQty = fake()->numberBetween(1, 50);
        $unitCost = fake()->randomFloat(2, 1, 500);

        return [
            'po_id' => PurchaseOrder::factory(),
            'product_id' => Product::factory(),
            'ordered_qty' => $orderedQty,
            'received_qty' => 0,
            'unit_cost' => $unitCost,
            'subtotal' => round($orderedQty * $unitCost, 2),
        ];
    }
}
