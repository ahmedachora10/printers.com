<?php

namespace App\Actions\PurchaseOrder;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeletePurchaseOrderAction
{
    public function handle(PurchaseOrder $po): bool
    {
        if (! $po->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن حذف أمر شراء بعد إرساله. استخدم الإلغاء بدلاً من ذلك.',
            ]);
        }

        return DB::transaction(function () use ($po) {
            $po->lines()->delete();

            return $po->delete();
        });
    }
}
