<?php

namespace Database\Factories;

use App\Enums\DeductionReasonEnum;
use App\Models\Branch;
use App\Models\EmployeeDeduction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDeduction>
 */
class EmployeeDeductionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'amount' => fake()->randomFloat(2, 25, 500),
            'reason' => fake()->randomElement([
                DeductionReasonEnum::Performance,
                DeductionReasonEnum::ExecutionError,
                DeductionReasonEnum::NonCompliance,
            ]),
            'reason_note' => null,
            'deducted_by' => User::factory(),
            'deducted_at' => now(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
