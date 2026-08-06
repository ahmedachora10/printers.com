<?php

namespace App\Models;

use App\Enums\PurchaseRequestStatusEnum;
use Database\Factories\PurchaseRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An internal purchase request raised by an employee or accountant, approved
 * or rejected by the branch admin, and optionally turned into an M29
 * purchase order afterwards.
 */
class PurchaseRequest extends Model
{
    /** @use HasFactory<PurchaseRequestFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'requested_by',
        'status',
        'notes',
        'decided_by',
        'decided_at',
        'decision_reason',
        'purchase_order_id',
    ];

    protected $casts = [
        'status' => PurchaseRequestStatusEnum::class,
        'decided_at' => 'datetime',
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<PurchaseRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class, 'request_id');
    }

    /** Estimated total of the request (lines with no estimate count as zero). */
    public function estimatedTotal(): float
    {
        return (float) $this->lines
            ->map(fn (PurchaseRequestLine $line) => $line->qty * (float) ($line->estimated_unit_cost ?? 0))
            ->sum();
    }

    /**
     * Narrows the query to what the given user is allowed to see, mirroring
     * PurchaseRequestPolicy::view: super-admin sees everything, a branch admin
     * their own branch, and an accountant/employee only their own requests.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->roleName?->isSuperAdmin()) {
            return $query;
        }

        if ($user->roleName?->isBranchAdmin()) {
            return $query->where('branch_id', $user->branchId);
        }

        return $query->where('requested_by', $user->id);
    }
}
