<?php

namespace App\Models;

use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'po_id',
        'product_id',
        'ordered_qty',
        'received_qty',
        'unit_cost',
        'subtotal',
    ];

    protected $casts = [
        'ordered_qty' => 'float',
        'received_qty' => 'float',
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    /** Quantity still awaiting delivery. */
    public function remainingQty(): float
    {
        return round(max(0, $this->ordered_qty - $this->received_qty), 2);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
