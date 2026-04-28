<?php

namespace App\Actions\Branch;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteBranchAction
{
    public function handle(Branch $branch): bool
    {
        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('branch_id', $branch->id)->exists()) {
                throw ValidationException::withMessages([
                    'branch' => 'لا يمكن حذف فرع مرتبط بفواتير.',
                ]);
            }
        }

        return DB::transaction(fn () => $branch->delete());
    }
}
