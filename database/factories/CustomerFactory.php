<?php

namespace Database\Factories;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'full_name'        => fake()->name(),
            'phone'            => fake()->unique()->numerify('05########'),
            'email'            => fake()->optional(0.6)->safeEmail(),
            'branch_id'        => Branch::factory(),
            'customer_type'    => CustomerTypeEnum::Individual,
            'company_name'     => null,
            'credit_limit'     => null,
            'agent_id'         => null,
            'points_balance'   => fake()->numberBetween(0, 5000),
            'cumulative_spend' => fake()->randomFloat(2, 0, 50000),
            'tier'             => CustomerTierEnum::None,
            'notes'            => null,
            'is_active'        => true,
        ];
    }

    public function corporate(): static
    {
        return $this->state(fn () => [
            'customer_type' => CustomerTypeEnum::Corporate,
            'company_name'  => fake()->company(),
        ]);
    }

    public function withCreditLimit(float $limit = 5000): static
    {
        return $this->state(fn () => ['credit_limit' => $limit]);
    }

    public function bronze(): static
    {
        return $this->state(fn () => [
            'tier'             => CustomerTierEnum::Bronze,
            'cumulative_spend' => fake()->randomFloat(2, 500, 1999),
        ]);
    }

    public function silver(): static
    {
        return $this->state(fn () => [
            'tier'             => CustomerTierEnum::Silver,
            'cumulative_spend' => fake()->randomFloat(2, 2000, 4999),
        ]);
    }

    public function gold(): static
    {
        return $this->state(fn () => [
            'tier'             => CustomerTierEnum::Gold,
            'cumulative_spend' => fake()->randomFloat(2, 5000, 100000),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
