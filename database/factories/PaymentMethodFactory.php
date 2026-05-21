<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'      => fake()->unique()->randomElement(['نقد', 'بطاقة بنكية', 'تحويل بنكي', 'مدى', 'أبل باي']),
            'is_active' => true,
        ];
    }
}
