<?php

namespace App\Actions\PurchaseOrder;

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\StockMovementTypeEnum;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a delivery against a purchase order. Each received quantity is pushed
 * through the immutable stock ledger as a `purchase_in` movement (so it lifts
 * products.current_stock and fires low-stock recovery just like any other
 * inbound), the matching line's received_qty is bumped, and the PO status is
 * recomputed (partial/received).
 */
class ReceivePurchaseOrderAction
{
    public function __construct(private RecordStockMovementAction $recordStockMovement) {}

    /** @param array<int, array{line_id: int, qty: float}> $receipts */
    public function handle(PurchaseOrder $po, array $receipts): PurchaseOrder
    {
        if (! $po->status->canReceive()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن استلام هذا الأمر في حالته الحالية.',
            ]);
        }

        return DB::transaction(function () use ($po, $receipts) {
            $lines = $po->lines()->with('product')->get()->keyBy('id');

            foreach ($receipts as $receipt) {
                $qty = round((float) $receipt['qty'], 2);

                if ($qty <= 0) {
                    continue;
                }

                $line = $lines->get($receipt['line_id']);

                if ($line === null) {
                    throw ValidationException::withMessages([
                        'receipts' => 'بند غير صالح لهذا الأمر.',
                    ]);
                }

                if ($line->received_qty + $qty > $line->ordered_qty) {
                    throw ValidationException::withMessages([
                        'receipts' => 'الكمية المستلمة تتجاوز الكمية المطلوبة.',
                    ]);
                }

                $this->recordStockMovement->handle(
                    $line->product,
                    StockMovementTypeEnum::PURCHASE_IN,
                    $qty,
                    [
                        'unit_cost' => $line->unit_cost,
                        'reference_id' => $po->id,
                        'reference_type' => PurchaseOrder::class,
                    ],
                );

                $line->increment('received_qty', $qty);
            }

            $po->load('lines');
            $po->recalculateStatus();

            return $po->refresh();
        });
    }
}
