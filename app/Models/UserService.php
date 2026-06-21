<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Per-employee commission rate for a branch service. Absence of a row means the
 * employee earns 0% commission for that service (zero fallback).
 */
class UserService extends Pivot
{
    protected $table = 'user_services';

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'branch_service_id',
        'commission_override_pct',
    ];

    protected $casts = [
        'commission_override_pct' => 'decimal:2',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BranchService, $this> */
    public function branchService(): BelongsTo
    {
        return $this->belongsTo(BranchService::class);
    }
}
