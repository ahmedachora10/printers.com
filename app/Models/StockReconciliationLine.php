<?php

namespace App\Models;

use Database\Factories\StockReconciliationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReconciliationLine extends Model
{
    /** @use HasFactory<StockReconciliationLineFactory> */
    use HasFactory;

    protected $fillable = [
        'reconciliation_id',
        'product_id',
        'system_qty',
        'physical_qty',
        'variance',
        'movement_id',
    ];

    protected $casts = [
        'system_qty' => 'float',
        'physical_qty' => 'float',
        'variance' => 'float',
    ];

    /** @return BelongsTo<StockReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(StockReconciliation::class, 'reconciliation_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }
}
