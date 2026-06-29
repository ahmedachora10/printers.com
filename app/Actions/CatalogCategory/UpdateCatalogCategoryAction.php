<?php

namespace App\Actions\CatalogCategory;

use App\Models\CatalogCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateCatalogCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(CatalogCategory $category, array $data): CatalogCategory
    {
        return DB::transaction(function () use ($category, $data) {
            $hasImageKey = array_key_exists('image', $data);
            /** @var UploadedFile|null $image */
            $image = Arr::pull($data, 'image');

            $category->update($data);

            if ($hasImageKey && $image) {
                $category->clearMediaCollection('image');
                $category->addMedia($image)->toMediaCollection('image');
            }

            return $category->fresh();
        });
    }
}
