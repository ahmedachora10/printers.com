<?php

namespace App\Models;

use Database\Factories\LoyaltyConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyConfig extends Model
{
    /** @use HasFactory<LoyaltyConfigFactory> */
    use HasFactory;

    protected $table = 'loyalty_config';

    protected $fillable = [
        'branch_id',
        'earning_rate',
        'redemption_rate',
        'min_redemption_points',
        'bronze_threshold',
        'silver_threshold',
        'gold_threshold',
        'bronze_discount_pct',
        'silver_discount_pct',
        'gold_discount_pct',
        'is_active',
    ];

    protected $casts = [
        'earning_rate' => 'decimal:4',
        'redemption_rate' => 'decimal:4',
        'min_redemption_points' => 'integer',
        'bronze_threshold' => 'decimal:2',
        'silver_threshold' => 'decimal:2',
        'gold_threshold' => 'decimal:2',
        'bronze_discount_pct' => 'decimal:2',
        'silver_discount_pct' => 'decimal:2',
        'gold_discount_pct' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * The active loyalty configuration for a branch, creating it from the
     * column defaults on first use so the program runs out of the box.
     */
    public static function forBranch(int $branchId): self
    {
        return static::firstOrCreate(['branch_id' => $branchId]);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
