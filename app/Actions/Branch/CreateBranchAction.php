<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateBranchAction
{
    public function handle(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            /** @var UploadedFile|null $logo */
            $logo = Arr::pull($data, 'logo');

            $branch = Branch::create($data);

            if ($logo) {
                $branch->addMedia($logo)->toMediaCollection('logo');
            }

            return $branch;
        });
    }
}
