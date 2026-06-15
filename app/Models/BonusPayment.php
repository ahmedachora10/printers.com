<?php

namespace App\Models;

use Database\Factories\BonusPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of a bonus payout against an achieved incentive plan.
 */
class BonusPayment extends Model
{
    /** @use HasFactory<BonusPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'incentive_plan_id',
        'paid_by',
        'amount',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<IncentivePlan, $this> */
    public function incentivePlan(): BelongsTo
    {
        return $this->belongsTo(IncentivePlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
