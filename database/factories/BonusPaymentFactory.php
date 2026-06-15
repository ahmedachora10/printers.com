<?php

namespace Database\Factories;

use App\Models\BonusPayment;
use App\Models\IncentivePlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BonusPayment>
 */
class BonusPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'incentive_plan_id' => IncentivePlan::factory(),
            'paid_by' => User::factory(),
            'amount' => fake()->randomFloat(2, 100, 2000),
            'paid_at' => now(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
