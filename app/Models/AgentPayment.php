<?php

namespace App\Models;

use Database\Factories\AgentPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of a rebate payout to an agent for a period.
 */
class AgentPayment extends Model
{
    /** @use HasFactory<AgentPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'branch_id',
        'period_start',
        'period_end',
        'total_invoices',
        'total_rebate',
        'paid_by',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_invoices' => 'integer',
        'total_rebate' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
