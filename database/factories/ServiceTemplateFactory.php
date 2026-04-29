<?php

namespace Database\Factories;

use App\Models\ServiceTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceTemplate>
 */
class ServiceTemplateFactory extends Factory
{
    protected $model = ServiceTemplate::class;

    public function definition(): array
    {
        return [
            'name'        => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(10),
            'is_active'   => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
