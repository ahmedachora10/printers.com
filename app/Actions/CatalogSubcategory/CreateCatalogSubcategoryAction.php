<?php

namespace App\Actions\CatalogSubcategory;

use App\Models\CatalogSubcategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateCatalogSubcategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): CatalogSubcategory
    {
        return DB::transaction(function () use ($data) {
            /** @var UploadedFile|null $image */
            $image = Arr::pull($data, 'image');

            $subcategory = CatalogSubcategory::create($data);

            if ($image) {
                $subcategory->addMedia($image)->toMediaCollection('image');
            }

            return $subcategory;
        });
    }
}
