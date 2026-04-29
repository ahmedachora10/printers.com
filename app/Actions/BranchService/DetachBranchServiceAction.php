<?php

namespace App\Actions\BranchService;

use App\Models\BranchService;
use Illuminate\Support\Facades\DB;

class DetachBranchServiceAction
{
    public function handle(BranchService $branchService): void
    {
        DB::transaction(fn (): bool => $branchService->delete());
    }
}
