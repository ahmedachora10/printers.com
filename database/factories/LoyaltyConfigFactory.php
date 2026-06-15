<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LoyaltyConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyConfig>
 */
class LoyaltyConfigFactory extends Factory
{
    protected $model = LoyaltyConfig::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'earning_rate' => 1,
            'redemption_rate' => 100,
            'min_redemption_points' => 500,
            'bronze_threshold' => 500,
            'silver_threshold' => 2000,
            'gold_threshold' => 5000,
            'bronze_discount_pct' => 2,
            'silver_discount_pct' => 5,
            'gold_discount_pct' => 8,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
