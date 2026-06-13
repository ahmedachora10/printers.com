<?php

namespace App\Actions\City;

use App\Models\City;

class CreateCityAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): City
    {
        return City::create($data);
    }
}
