<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class CreateSupplierAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): Supplier
    {
        $data['branch_id'] = auth()->user()->branchId;

        return DB::transaction(fn () => Supplier::create($data));
    }
}
