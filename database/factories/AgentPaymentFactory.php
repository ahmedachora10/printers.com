<?php

namespace Database\Factories;

use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentPayment>
 */
class AgentPaymentFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-3 months', '-1 month');
        $end = fake()->dateTimeBetween($start, 'now');

        return [
            'agent_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
            'total_invoices' => fake()->numberBetween(1, 20),
            'total_rebate' => fake()->randomFloat(2, 50, 5000),
            'paid_by' => User::factory(),
            'paid_at' => now(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
