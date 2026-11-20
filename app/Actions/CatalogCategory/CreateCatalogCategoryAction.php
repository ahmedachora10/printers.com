<?php

namespace App\Actions\CatalogCategory;

use App\Models\CatalogCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateCatalogCategoryAction
{
    /**
     * One independent category per branch in `branch_ids` — or one general
     * category when it is empty. Editing one copy never touches the others.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, CatalogCategory>
     */
    public function handle(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            /** @var UploadedFile|null $image */
            $image = Arr::pull($data, 'image');
            $branchIds = Arr::pull($data, 'branch_ids') ?: [null];
            unset($data['branch_id']);

            $categories = collect($branchIds)->map(fn (?int $id) => CatalogCategory::create([...$data, 'branch_id' => $id]));

            if ($image) {
                $media = $categories->first()->addMedia($image)->toMediaCollection('image');
                $categories->skip(1)->each(fn (CatalogCategory $copy) => $media->copy($copy, 'image'));
            }

            return $categories;
        });
    }
}
