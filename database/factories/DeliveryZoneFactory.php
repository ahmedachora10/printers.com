<?php

namespace Database\Factories;

use App\Enums\DeliveryZoneTypeEnum;
use App\Models\Branch;
use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryZone>
 */
class DeliveryZoneFactory extends Factory
{
    protected $model = DeliveryZone::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'type' => DeliveryZoneTypeEnum::Area,
            'name' => 'حي '.fake()->unique()->word(),
            'from_km' => null,
            'to_km' => null,
            'price' => 25,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** شريحة مسافة؛ `$to = null` تصنع الشريحة المفتوحة «أكثر من كذا». */
    public function distance(float $from, ?float $to = null): static
    {
        return $this->state(fn () => [
            'type' => DeliveryZoneTypeEnum::Distance,
            'name' => $to === null ? "أكثر من {$from} كم" : "من {$from} إلى {$to} كم",
            'from_km' => $from,
            'to_km' => $to,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
