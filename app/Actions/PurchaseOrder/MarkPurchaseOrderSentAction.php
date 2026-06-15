<?php

namespace App\Actions\PurchaseOrder;

use App\Enums\PurchaseOrderStatusEnum;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkPurchaseOrderSentAction
{
    public function handle(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatusEnum::DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'يمكن إرسال المسودات فقط.',
            ]);
        }

        return DB::transaction(function () use ($po) {
            $po->update(['status' => PurchaseOrderStatusEnum::SENT]);

            return $po->refresh();
        });
    }
}
