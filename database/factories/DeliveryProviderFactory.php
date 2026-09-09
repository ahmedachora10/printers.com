<?php

namespace Database\Factories;

use App\Enums\DeliveryProviderTypeEnum;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryProvider>
 */
class DeliveryProviderFactory extends Factory
{
    protected $model = DeliveryProvider::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->name(),
            'type' => DeliveryProviderTypeEnum::Driver,
            'phone' => '05'.fake()->numerify('########'),
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => ['type' => DeliveryProviderTypeEnum::Company]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
