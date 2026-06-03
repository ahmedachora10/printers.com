<?php

namespace Database\Factories;

use App\Enums\CommissionSourceTypeEnum;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionLedger>
 */
class CommissionLedgerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'invoice_line_id' => fake()->numberBetween(1, 1000),
            'invoice_line_type' => 'service',
            'amount' => fake()->randomFloat(2, 5, 500),
            'is_tahazir' => false,
            'tier_applied' => null,
            'source_type' => CommissionSourceTypeEnum::STANDARD,
            'referral_order_id' => null,
            'earned_at' => now(),
            'paid_at' => null,
        ];
    }

    public function tahazir(): static
    {
        return $this->state(['is_tahazir' => true]);
    }

    public function paid(): static
    {
        return $this->state(['paid_at' => now()]);
    }
}
