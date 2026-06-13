<?php

namespace App\Actions\BranchService;

use App\Models\BranchService;
use Illuminate\Support\Facades\DB;

class UpdateBranchServiceAction
{
    /** @param array<string, mixed> $data */
    public function handle(BranchService $branchService, array $data): BranchService
    {
        return DB::transaction(function () use ($branchService, $data): BranchService {
            $branchService->update($data);

            return $branchService->fresh();
        });
    }
}
