<?php

namespace App\Models;

use App\Enums\IncentiveBonusTypeEnum;
use App\Enums\IncentivePlanStatusEnum;
use Database\Factories\IncentivePlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly sales target for an employee. When their service sales reach the
 * target the plan becomes payable for the configured bonus.
 */
class IncentivePlan extends Model
{
    /** @use HasFactory<IncentivePlanFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'period_month',
        'period_year',
        'target_amount',
        'bonus_type',
        'bonus_value',
        'achieved_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'integer',
        'period_year' => 'integer',
        'target_amount' => 'decimal:2',
        'bonus_type' => IncentiveBonusTypeEnum::class,
        'bonus_value' => 'decimal:2',
        'achieved_amount' => 'decimal:2',
        'status' => IncentivePlanStatusEnum::class,
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

    /** @return HasMany<BonusPayment, $this> */
    public function bonusPayments(): HasMany
    {
        return $this->hasMany(BonusPayment::class);
    }

    public function isTargetMet(): bool
    {
        return (float) $this->achieved_amount >= (float) $this->target_amount;
    }

    /**
     * The bonus owed if this plan is paid out now: a flat amount for `fixed`,
     * or a percentage **of the target** for `percentage`.
     *
     * تاسك 73: the percentage used to be taken from `achieved_amount`, which
     * made the bonus a commission on sales rather than a reward for reaching
     * an agreed target. The bonus is never paid before the target is met
     * (PayBonusAction refuses an unmet plan), so measuring it on the achieved
     * figure paid whoever exactly hit the target *less* than whoever overshot
     * it — which is not what was agreed. A target of 20,000 at 10% is 2,000,
     * for everyone who reaches it.
     */
    public function bonusAmount(): float
    {
        return match ($this->bonus_type) {
            IncentiveBonusTypeEnum::Fixed => (float) $this->bonus_value,
            IncentiveBonusTypeEnum::Percentage => round((float) $this->target_amount * (float) $this->bonus_value / 100, 2),
        };
    }
}
