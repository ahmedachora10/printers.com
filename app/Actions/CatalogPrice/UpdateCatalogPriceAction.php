<?php

namespace App\Actions\CatalogPrice;

use App\Models\CatalogPrice;
use Illuminate\Support\Facades\DB;

class UpdateCatalogPriceAction
{
    /** @param array<string, mixed> $data */
    public function handle(CatalogPrice $price, array $data): CatalogPrice
    {
        return DB::transaction(function () use ($price, $data) {
            $price->update($data);

            return $price->fresh();
        });
    }
}
