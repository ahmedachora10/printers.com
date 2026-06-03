<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CommissionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionPayment>
 */
class CommissionPaymentFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'total_amount' => fake()->randomFloat(2, 50, 2000),
            'paid_by' => User::factory(),
            'paid_at' => now(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
