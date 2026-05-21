<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'      => fake()->randomElement(['نقد', 'بطاقة بنكية', 'تحويل بنكي', 'مدى', 'أبل باي']),
            'branch_id' => Branch::factory(),
            'is_active' => true,
        ];
    }
}
