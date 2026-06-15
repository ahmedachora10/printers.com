<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class UpdateSupplierAction
{
    /** @param array<string, mixed> $data */
    public function handle(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier->update($data);

            return $supplier->refresh();
        });
    }
}
