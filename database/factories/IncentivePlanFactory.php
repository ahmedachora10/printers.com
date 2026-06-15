<?php

namespace Database\Factories;

use App\Enums\IncentiveBonusTypeEnum;
use App\Enums\IncentivePlanStatusEnum;
use App\Models\Branch;
use App\Models\IncentivePlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncentivePlan>
 */
class IncentivePlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'period_month' => fake()->numberBetween(1, 12),
            'period_year' => (int) now()->year,
            'target_amount' => fake()->randomFloat(2, 1000, 20000),
            'bonus_type' => fake()->randomElement(IncentiveBonusTypeEnum::cases()),
            'bonus_value' => fake()->randomFloat(2, 100, 1000),
            'achieved_amount' => 0,
            'status' => IncentivePlanStatusEnum::Active,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
