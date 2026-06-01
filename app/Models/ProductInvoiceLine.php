<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'product_id',
        'product_name',
        'sku',
        'qty',
        'unit_price',
        'discount_pct',
        'subtotal',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    /** @return BelongsTo<ProductInvoice, self> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ProductInvoice::class, 'invoice_id');
    }

    /** @return BelongsTo<Product, self> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
