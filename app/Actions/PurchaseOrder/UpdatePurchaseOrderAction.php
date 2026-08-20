<?php

namespace App\Actions\PurchaseOrder;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrderAction
{
    /** @param array<string, mixed> $data */
    public function handle(PurchaseOrder $po, array $data): PurchaseOrder
    {
        if (! $po->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن تعديل أمر شراء بعد إرساله.',
            ]);
        }

        return DB::transaction(function () use ($po, $data) {
            $po->update([
                'supplier_id' => $data['supplier_id'] ?? null,
                'order_date' => $data['order_date'],
                'expected_delivery' => $data['expected_delivery'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Lines are immutable identifiers here only in the receiving sense;
            // while still a draft we replace them wholesale.
            $po->lines()->delete();

            foreach ($data['lines'] as $line) {
                $po->lines()->create([
                    'product_id' => $line['product_id'],
                    'ordered_qty' => round((float) $line['ordered_qty'], 2),
                    'received_qty' => 0,
                    'unit_cost' => $line['unit_cost'],
                    'subtotal' => round($line['ordered_qty'] * $line['unit_cost'], 2),
                ]);
            }

            return $po->refresh();
        });
    }
}
