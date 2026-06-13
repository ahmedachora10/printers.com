<?php

namespace App\Models;

use App\Enums\CommissionSourceTypeEnum;
use Database\Factories\CommissionLedgerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommissionLedger extends Model
{
    /** @use HasFactory<CommissionLedgerFactory> */
    use HasFactory;

    protected $table = 'commission_ledger';

    /**
     * Append-only ledger — no created_at/updated_at; entries carry their own
     * earned_at / paid_at timestamps.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'branch_id',
        'invoice_line_id',
        'invoice_line_type',
        'amount',
        'is_tahazir',
        'tier_applied',
        'source_type',
        'referral_order_id',
        'earned_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_tahazir' => 'boolean',
        'tier_applied' => 'integer',
        'source_type' => CommissionSourceTypeEnum::class,
        'earned_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return MorphTo<Model, $this> */
    public function invoiceLine(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'invoice_line_type', 'invoice_line_id');
    }
}
