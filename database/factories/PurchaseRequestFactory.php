<?php

namespace Database\Factories;

use App\Enums\PurchaseRequestStatusEnum;
use App\Models\Branch;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'requested_by' => User::factory(),
            'status' => PurchaseRequestStatusEnum::PENDING,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseRequestStatusEnum::APPROVED,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseRequestStatusEnum::REJECTED,
            'decided_at' => now(),
            'decision_reason' => fake()->sentence(),
        ]);
    }
}
