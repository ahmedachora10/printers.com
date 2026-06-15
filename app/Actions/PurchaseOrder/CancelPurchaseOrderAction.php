<?php

namespace App\Actions\PurchaseOrder;

use App\Enums\PurchaseOrderStatusEnum;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPurchaseOrderAction
{
    public function handle(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->status->canCancel()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن إلغاء أمر شراء تم استلام بضائع منه.',
            ]);
        }

        return DB::transaction(function () use ($po) {
            $po->update(['status' => PurchaseOrderStatusEnum::CANCELLED]);

            return $po->refresh();
        });
    }
}
