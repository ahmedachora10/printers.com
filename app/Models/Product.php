<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'category_id',
        'unit_id',
        'is_sqm',
        'sku',
        'name',
        'cost_price',
        'selling_price',
        'min_stock_level',
        'barcode',
        'is_active',
    ];

    protected $casts = [
        'is_sqm' => 'boolean',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        // كميات المخزون عشرية منذ تاسك 51 — المنتج المسعّر بالمتر المربع يُخصم
        // بكسور المتر. تُقرأ float لا decimal:2 لأنها أرقام حساب لا مبالغ تُعرض.
        'min_stock_level' => 'float',
        'current_stock' => 'float',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** @return BelongsTo<ProductUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    /** @return HasMany<StockMovement, $this> */
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
            'current_stock' => round((float) $this->stockMovements()->sum('qty'), 2),
        ])->save();
    }
}
