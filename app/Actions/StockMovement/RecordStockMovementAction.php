<?php

namespace App\Actions\StockMovement;

use App\Enums\StockMovementTypeEnum;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for writing to the immutable stock ledger. Reused by the
 * POS, refunds, purchase-order receiving and reconciliation flows. Callers pass
 * a positive quantity; the action signs it according to the movement direction
 * so that products.current_stock = SUM(stock_movements.qty).
 */
class RecordStockMovementAction
{
    public function handle(
        Product $product,
        StockMovementTypeEnum $type,
        int $qty,
        array $attributes = [],
    ): StockMovement {
        $signedQty = $type->isInbound() ? abs($qty) : -abs($qty);

        return DB::transaction(fn () => StockMovement::create([
            'product_id' => $product->id,
            'branch_id' => $product->branch_id,
            'type' => $type,
            'qty' => $signedQty,
            'unit_cost' => $attributes['unit_cost'] ?? null,
            'reference_id' => $attributes['reference_id'] ?? null,
            'reference_type' => $attributes['reference_type'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'created_by' => $attributes['created_by'] ?? Auth::id(),
        ]));
    }
}
