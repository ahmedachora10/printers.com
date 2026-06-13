<?php

namespace App\Models;

use App\Enums\StockMovementTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Immutable inventory ledger. Rows are only ever inserted — never updated or
 * deleted. Corrections are made with new adjustment entries. The signed `qty`
 * drives the computed `products.current_stock` (SUM of qty).
 */
class StockMovement extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'branch_id',
        'type',
        'qty',
        'unit_cost',
        'reference_id',
        'reference_type',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'type' => StockMovementTypeEnum::class,
        'qty' => 'integer',
        'unit_cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(fn (StockMovement $movement) => $movement->product->recalculateStock());

        static::updating(function (): void {
            throw new RuntimeException('Stock movements are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Stock movements are immutable and cannot be deleted.');
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
