<?php

namespace App\Actions\City;

use App\Models\City;

class UpdateCityAction
{
    public function handle(City $city, array $data): City
    {
        $city->update($data);
        
        return $city->fresh();
    }
}
