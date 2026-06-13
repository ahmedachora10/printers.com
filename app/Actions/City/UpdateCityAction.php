<?php

namespace App\Actions\City;

use App\Models\City;

class UpdateCityAction
{
    /** @param array<string, mixed> $data */
    public function handle(City $city, array $data): City
    {
        $city->update($data);

        return $city->fresh();
    }
}
