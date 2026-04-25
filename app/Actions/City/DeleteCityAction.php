<?php

namespace App\Actions\City;

use App\Models\City;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteCityAction
{
    public function handle(City $city): bool
    {
        if (
            Schema::hasTable('branches') &&
            DB::table('branches')->where('city_id', $city->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'city' => 'لا يمكن حذف مدينة مرتبطة بفروع.',
            ]);
        }

        return $city->delete();
    }
}
