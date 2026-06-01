<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'category_id',
        'unit_id',
        'sku',
        'name',
        'cost_price',
        'selling_price',
        'min_stock_level',
        'barcode',
        'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'min_stock_level' => 'integer',
        'current_stock' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Branch, self> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ProductCategory, self> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** @return BelongsTo<ProductUnit, self> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    /** @return HasMany<StockMovement> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Recompute the cached current_stock from the immutable ledger.
     * current_stock is read-only everywhere else — only this method writes it.
     */
    public function recalculateStock(): void
    {
        $this->forceFill([
            'current_stock' => (int) $this->stockMovements()->sum('qty'),
        ])->save();
    }
}
