<?php

namespace Database\Factories;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Enums\AgentTypeEnum;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfile>
 */
class AgentProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agent_type' => fake()->randomElement(AgentTypeEnum::cases())->value,
            'discount_mode' => fake()->randomElement(AgentDiscountModeEnum::cases())->value,
            'discount_type' => AgentDiscountTypeEnum::Percentage->value,
            'rate' => fake()->randomFloat(2, 1, 25),
            'commercial_reg_no' => fake()->optional()->numerify('##########'),
        ];
    }

    public function rebate(): static
    {
        return $this->state(fn () => ['discount_mode' => AgentDiscountModeEnum::Rebate->value]);
    }

    public function discount(): static
    {
        return $this->state(fn () => ['discount_mode' => AgentDiscountModeEnum::Discount->value]);
    }
}
