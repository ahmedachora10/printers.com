<?php

namespace App\Models;

use App\Enums\StockMovementTypeEnum;
use Database\Factories\StockMovementFactory;
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
    /** @use HasFactory<StockMovementFactory> */
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
        'service_invoice_line_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'type' => StockMovementTypeEnum::class,
        'qty' => 'float',
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

    /**
     * سطر الخدمة الذي استهلك هذه الخامة — لحركات خامات فواتير الخدمات وحدها،
     * وفارغٌ لكل ما عداها ولحركاتِ ما قبل إضافة العمود.
     *
     * @return BelongsTo<ServiceInvoiceLine, $this>
     */
    public function serviceInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoiceLine::class);
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
