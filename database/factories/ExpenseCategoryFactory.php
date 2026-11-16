<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Database\Factories\Concerns\ForBranches;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
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
