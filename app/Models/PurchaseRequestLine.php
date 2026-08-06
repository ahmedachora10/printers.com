<?php

namespace App\Models;

use Database\Factories\PurchaseRequestLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestLine extends Model
{
    /** @use HasFactory<PurchaseRequestLineFactory> */
    use HasFactory;

    protected $fillable = [
        'request_id',
        'product_id',
        'item_name',
        'qty',
        'estimated_unit_cost',
        'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
        'estimated_unit_cost' => 'decimal:2',
    ];

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'request_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
