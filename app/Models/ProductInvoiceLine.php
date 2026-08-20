<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'product_name',
        'sku',
        'qty',
        'width_cm',
        'height_cm',
        'pieces',
        'unit_price',
        'discount_pct',
        'subtotal',
    ];

    protected $casts = [
        // كمية عشرية: سطر المنتج المسعّر بالمتر المربع يحمل مساحته لا عدد قطعه.
        'qty' => 'float',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'pieces' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    /** @return BelongsTo<ProductInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ProductInvoice::class, 'invoice_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
