<?php

namespace App\Actions\StockMovement;

use App\Enums\StockMovementTypeEnum;
use App\Models\Product;
use App\Models\StockMovement;
use App\Notifications\LowStockNotification;
use App\Support\BranchNotifiables;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Single entry point for writing to the immutable stock ledger. Reused by the
 * POS, refunds, purchase-order receiving and reconciliation flows. Callers pass
 * a positive quantity; the action signs it according to the movement direction
 * so that products.current_stock = SUM(stock_movements.qty).
 *
 * Quantities are decimal since تاسك 51: a product priced per square metre moves
 * in fractions of a metre, so the ledger stores DECIMAL(12,2) and every quantity
 * is rounded to two places here — never left as a raw float.
 */
class RecordStockMovementAction
{
    /** @param array<string, mixed> $attributes */
    public function handle(
        Product $product,
        StockMovementTypeEnum $type,
        float $qty,
        array $attributes = [],
    ): StockMovement {
        $signedQty = round($type->isInbound() ? abs($qty) : -abs($qty), 2);

        return DB::transaction(function () use ($product, $type, $signedQty, $attributes) {
            $movement = StockMovement::create([
                'product_id' => $product->id,
                'branch_id' => $product->branch_id,
                'type' => $type,
                'qty' => $signedQty,
                'unit_cost' => $attributes['unit_cost'] ?? null,
                'reference_id' => $attributes['reference_id'] ?? null,
                'reference_type' => $attributes['reference_type'] ?? null,
                'service_invoice_line_id' => $attributes['service_invoice_line_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $attributes['created_by'] ?? Auth::id(),
            ]);

            $this->maybeNotifyLowStock($product, $signedQty);

            return $movement;
        });
    }

    /**
     * Alert branch staff when an outbound movement pulls a product's stock down
     * to (or below) its min level — but only on the *downward crossing*, so a
     * product that is already low doesn't re-notify on every subsequent sale.
     * Sent within the surrounding transaction: the database-channel rows then
     * roll back alongside a failed sale, so no orphan alerts are emitted. The
     * model's `created` hook has already refreshed `current_stock`.
     */
    private function maybeNotifyLowStock(Product $product, float $signedQty): void
    {
        if ($signedQty >= 0 || $product->min_stock_level <= 0) {
            return;
        }

        $newStock = round((float) $product->stockMovements()->sum('qty'), 2);
        $prevStock = round($newStock - $signedQty, 2);

        if ($prevStock <= $product->min_stock_level || $newStock > $product->min_stock_level) {
            return;
        }

        Notification::send(
            BranchNotifiables::forBranch($product->branch_id, ['branch-admin', 'accountant']),
            new LowStockNotification($product, $newStock),
        );
    }
}
