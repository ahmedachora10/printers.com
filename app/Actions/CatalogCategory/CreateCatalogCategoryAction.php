<?php

namespace App\Actions\CatalogCategory;

use App\Models\CatalogCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateCatalogCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): CatalogCategory
    {
        return DB::transaction(function () use ($data) {
            /** @var UploadedFile|null $image */
            $image = Arr::pull($data, 'image');

            $category = CatalogCategory::create($data);

            if ($image) {
                $category->addMedia($image)->toMediaCollection('image');
            }

            return $category;
        });
    }
}
