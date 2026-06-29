<?php

namespace App\Actions\CatalogPrice;

use App\Models\CatalogPrice;
use Illuminate\Support\Facades\DB;

class DeleteCatalogPriceAction
{
    public function handle(CatalogPrice $price): ?bool
    {
        return DB::transaction(fn () => $price->delete());
    }
}
