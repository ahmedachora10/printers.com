<?php

namespace App\Actions\CatalogSubcategory;

use App\Models\CatalogSubcategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateCatalogSubcategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(CatalogSubcategory $subcategory, array $data): CatalogSubcategory
    {
        return DB::transaction(function () use ($subcategory, $data) {
            $hasImageKey = array_key_exists('image', $data);
            /** @var UploadedFile|null $image */
            $image = Arr::pull($data, 'image');

            $subcategory->update($data);

            if ($hasImageKey && $image) {
                $subcategory->clearMediaCollection('image');
                $subcategory->addMedia($image)->toMediaCollection('image');
            }

            return $subcategory->fresh();
        });
    }
}
