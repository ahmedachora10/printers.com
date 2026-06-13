<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->randomFloat(2, 1, 20);
        $unitPrice = fake()->randomFloat(2, 5, 500);

        return [
            'expense_category_id' => ExpenseCategory::factory(),
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'total' => round($qty * $unitPrice, 2),
            'supplier_name' => fake()->company(),
            'receipt_reference' => fake()->bothify('REF-####'),
            'receipt_path' => null,
            'comment' => fake()->optional()->sentence(),
            'date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
        ];
    }
}
