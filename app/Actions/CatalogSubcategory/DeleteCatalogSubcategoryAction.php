<?php

namespace App\Actions\CatalogSubcategory;

use App\Models\CatalogSubcategory;
use Illuminate\Support\Facades\DB;

class DeleteCatalogSubcategoryAction
{
    public function handle(CatalogSubcategory $subcategory): ?bool
    {
        return DB::transaction(fn () => $subcategory->delete());
    }
}
