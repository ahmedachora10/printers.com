<?php

namespace Database\Factories;

use App\Enums\CouponDiscountTypeEnum;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        $type  = fake()->randomElement(CouponDiscountTypeEnum::cases());
        $value = $type === CouponDiscountTypeEnum::Percentage
            ? fake()->numberBetween(5, 50)
            : fake()->numberBetween(10, 200);

        return [
            'code'           => strtoupper(fake()->bothify('??##??##')),
            'branch_id'      => Branch::factory(),
            'discount_type'  => $type,
            'discount_value' => $value,
            'capacity'       => fake()->optional(0.6)->numberBetween(10, 500),
            'used_count'     => 0,
            'expires_at'     => fake()->optional(0.6)->dateTimeBetween('now', '+6 months'),
            'is_active'      => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attrs) => [
            'capacity'   => 5,
            'used_count' => 5,
        ]);
    }
}
