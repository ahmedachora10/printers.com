<?php

namespace App\Actions\StockReconciliation;

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\StockMovementTypeEnum;
use App\Models\StockReconciliation;
use App\Models\StockReconciliationLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a stock count: every line whose physical count differs from the
 * system snapshot is pushed through the immutable stock ledger as an
 * adjustment_in / adjustment_out movement (which also refreshes
 * products.current_stock), and the movement is linked back to the line for
 * audit. Zero-variance lines post nothing. Completion is final.
 */
class CompleteStockReconciliationAction
{
    public function __construct(private RecordStockMovementAction $recordStockMovement) {}

    public function handle(StockReconciliation $reconciliation): StockReconciliation
    {
        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'reconciliation' => 'هذا الجرد مكتمل بالفعل.',
            ]);
        }

        return DB::transaction(function () use ($reconciliation) {
            $lines = $reconciliation->lines()->with('product')->get();

            $lines->filter(fn (StockReconciliationLine $line) => $line->variance !== 0)
                ->each(function (StockReconciliationLine $line) use ($reconciliation) {
                    $movement = $this->recordStockMovement->handle(
                        $line->product,
                        $line->variance > 0
                            ? StockMovementTypeEnum::ADJUSTMENT_IN
                            : StockMovementTypeEnum::ADJUSTMENT_OUT,
                        abs($line->variance),
                        [
                            'unit_cost' => $line->product->cost_price,
                            'reference_id' => $reconciliation->id,
                            'reference_type' => StockReconciliation::class,
                            'notes' => sprintf('جرد مخزون #%d', $reconciliation->id),
                        ],
                    );

                    $line->update(['movement_id' => $movement->id]);
                });

            $reconciliation->update(['completed_at' => now()]);

            return $reconciliation->refresh();
        });
    }
}
