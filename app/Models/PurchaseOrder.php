<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatusEnum;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'po_number',
        'branch_id',
        'supplier_id',
        'ordered_by',
        'order_date',
        'expected_delivery',
        'status',
        'notes',
    ];

    protected $casts = [
        'status' => PurchaseOrderStatusEnum::class,
        'order_date' => 'date',
        'expected_delivery' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('inventory');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'po_id');
    }

    /** Total ordered value of the PO (sum of line subtotals). */
    public function total(): float
    {
        return (float) $this->lines->sum('subtotal');
    }

    /**
     * Recompute status from the lines' received quantities. Fully received on
     * every line → received; any received but not all → partial; otherwise the
     * status is left untouched (draft/sent).
     */
    public function recalculateStatus(): void
    {
        $lines = $this->lines;

        $fullyReceived = $lines->every(fn (PurchaseOrderLine $line) => $line->received_qty >= $line->ordered_qty);
        $anyReceived = $lines->contains(fn (PurchaseOrderLine $line) => $line->received_qty > 0);

        if ($fullyReceived) {
            $this->status = PurchaseOrderStatusEnum::RECEIVED;
        } elseif ($anyReceived) {
            $this->status = PurchaseOrderStatusEnum::PARTIAL;
        }

        $this->save();
    }
}
