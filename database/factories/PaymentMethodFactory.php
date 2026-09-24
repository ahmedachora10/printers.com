<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['نقد', 'بطاقة بنكية', 'تحويل بنكي', 'مدى', 'أبل باي']),
            'is_active' => true,
            'requires_attachment' => false,
        ];
    }

    /**
     * A method that mandates a receipt upload (e.g. bank transfer).
     */
    public function requiresAttachment(): static
    {
        return $this->state(fn () => ['requires_attachment' => true]);
    }

    /**
     * A cash method — the sales report takes expenses off these alone (تاسك 97).
     */
    public function cash(): static
    {
        return $this->state(fn () => ['is_cash' => true]);
    }

    /** A network (card-terminal) method — reconciled per device (تاسك 121). */
    public function network(): static
    {
        return $this->state(fn () => ['is_network' => true]);
    }
}
