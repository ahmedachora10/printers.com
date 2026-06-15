<?php

namespace App\Actions\Supplier;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteSupplierAction
{
    public function handle(Supplier $supplier): bool
    {
        if ($supplier->purchaseOrders()->exists()) {
            throw ValidationException::withMessages([
                'supplier' => 'لا يمكن حذف مورد مرتبط بأوامر شراء.',
            ]);
        }

        return DB::transaction(fn () => $supplier->delete());
    }
}
