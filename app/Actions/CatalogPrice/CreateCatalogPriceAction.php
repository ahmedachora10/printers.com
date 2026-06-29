<?php

namespace App\Actions\CatalogPrice;

use App\Models\CatalogPrice;
use Illuminate\Support\Facades\DB;

class CreateCatalogPriceAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): CatalogPrice
    {
        return DB::transaction(fn () => CatalogPrice::create($data));
    }
}
