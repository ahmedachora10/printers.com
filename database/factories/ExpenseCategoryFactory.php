<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }

    /** تاسك 238: فئةٌ نطاقها هذه الفروع؛ بلا استدعاء = عامة. */
    public function forBranches(int ...$branchIds): static
    {
        return $this->afterCreating(fn (ExpenseCategory $category) => $category->branches()->sync($branchIds));
    }
}
