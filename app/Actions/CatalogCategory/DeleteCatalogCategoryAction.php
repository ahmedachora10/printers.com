<?php

namespace App\Actions\CatalogCategory;

use App\Models\CatalogCategory;
use Illuminate\Support\Facades\DB;

class DeleteCatalogCategoryAction
{
    public function handle(CatalogCategory $category): ?bool
    {
        return DB::transaction(fn () => $category->delete());
    }
}
