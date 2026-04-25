<?php

namespace App\Actions\City;

use App\Models\City;

class CreateCityAction
{
    public function handle(array $data): City
    {
        return City::create($data);
    }
}
