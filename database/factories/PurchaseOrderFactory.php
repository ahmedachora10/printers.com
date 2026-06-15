<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatusEnum;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        $branch = Branch::factory();

        return [
            'po_number' => 'PO-'.fake()->unique()->numerify('###-#####'),
            'branch_id' => $branch,
            'supplier_id' => Supplier::factory(),
            'ordered_by' => User::factory(),
            'order_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'expected_delivery' => fake()->optional()->dateTimeBetween('now', '+1 month')?->format('Y-m-d'),
            'status' => PurchaseOrderStatusEnum::DRAFT,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatusEnum::SENT]);
    }
}
