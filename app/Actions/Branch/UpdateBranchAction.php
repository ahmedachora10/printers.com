<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateBranchAction
{
    public function handle(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            /** @var UploadedFile|null $logo */
            $logo = Arr::pull($data, 'logo');

            $branch->update($data);

            if ($logo) {
                $branch->clearMediaCollection('logo');
                $branch->addMedia($logo)->toMediaCollection('logo');
            }

            return $branch->fresh();
        });
    }
}

