<?php

namespace App\Models;

use Database\Factories\StockReconciliationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A physical stock count for one branch. Lines snapshot each product's system
 * quantity at the moment the count starts; completing the reconciliation posts
 * the variances into the immutable stock ledger and freezes the record —
 * completed reconciliations are never edited or deleted (their adjustments
 * live on as stock_movements rows).
 */
class StockReconciliation extends Model
{
    /** @use HasFactory<StockReconciliationFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'branch_id',
        'initiated_by',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** @return HasMany<StockReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockReconciliationLine::class, 'reconciliation_id');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
