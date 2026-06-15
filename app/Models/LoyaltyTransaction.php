<?php

namespace App\Models;

use App\Enums\LoyaltyTransactionTypeEnum;
use Database\Factories\LoyaltyTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LoyaltyTransaction extends Model
{
    /** @use HasFactory<LoyaltyTransactionFactory> */
    use HasFactory;

    /**
     * Append-only ledger — only created_at, never updated.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'invoice_type',
        'type',
        'points',
        'balance_after',
        'notes',
    ];

    protected $casts = [
        'type' => LoyaltyTransactionTypeEnum::class,
        'points' => 'integer',
        'balance_after' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return MorphTo<Model, $this> */
    public function invoice(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'invoice_type', 'invoice_id');
    }
}
