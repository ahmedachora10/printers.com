<?php

namespace App\Models;

use App\Enums\IncentiveBonusTypeEnum;
use App\Enums\IncentivePlanStatusEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\IncentivePlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'tiers',
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

    /**
     * الخطط الواقعة داخل مدى تاريخين.
     *
     * الخطة شهرٌ لا يوم، فلا عمود تاريخٍ تُقارَن به. المقارنة إذاً على مفتاح
     * `السنة×100 + الشهر` — وهو بعينه `Ym` — فتدخل خطةُ 08/2026 ما دام المدى
     * لامس أيَّ يومٍ من أغسطس، ولا يسقط شهرٌ لأن المدى بدأ في منتصفه.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInPeriodRange(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): void
    {
        $key = '(period_year * 100 + period_month)';

        $query
            ->when($from, fn (Builder $q) => $q->whereRaw($key.' >= ?', [(int) $from->format('Ym')]))
            ->when($to, fn (Builder $q) => $q->whereRaw($key.' <= ?', [(int) $to->format('Ym')]));
    }

    /**
     * تاسك 105: شرائح الخطة `[{threshold, value}]` مرتّبة تصاعدياً بالعتبة.
     *
     * خطةٌ بلا شرائح مخزّنة (كل خطة قبل التاسك) شريحتها الوحيدة هي الهدف
     * والمكافأة. والكتابة تنسخ أدنى شريحة إلى `target_amount`/`bonus_value`،
     * فالهدف يبقى «أدنى ما يستحقّ به الموظف مكافأة» لكل قارئٍ قديم.
     *
     * @return Attribute<array<int, array{threshold: string, value: string}>, array<int, array{threshold: mixed, value: mixed}>>
     */
    protected function tiers(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => $value
                ? json_decode($value, true)
                : [['threshold' => (string) ($attributes['target_amount'] ?? '0'), 'value' => (string) ($attributes['bonus_value'] ?? '0')]],
            set: function (array $tiers) {
                $tiers = collect($tiers)
                    ->map(fn (array $t) => ['threshold' => number_format((float) $t['threshold'], 2, '.', ''), 'value' => number_format((float) $t['value'], 2, '.', '')])
                    ->sortBy(fn (array $t) => (float) $t['threshold'])
                    ->values()
                    ->all();

                return [
                    'tiers' => json_encode($tiers),
                    'target_amount' => $tiers[0]['threshold'],
                    'bonus_value' => $tiers[0]['value'],
                ];
            },
        );
    }

    public function isMultiTier(): bool
    {
        return count($this->tiers) > 1;
    }

    public function periodEnded(): bool
    {
        return CarbonImmutable::create($this->period_year, $this->period_month, 1)->endOfMonth()->isPast();
    }

    /**
     * أعلى شريحة بلغها المحقَّق، ورقمها من 1 — أو null إن لم يبلغ أدناها.
     *
     * @return array{number: int, threshold: float, value: float}|null
     */
    public function reachedTier(): ?array
    {
        $reached = null;

        foreach ($this->tiers as $i => $tier) {
            if ((float) $this->achieved_amount >= (float) $tier['threshold']) {
                $reached = ['number' => $i + 1, 'threshold' => (float) $tier['threshold'], 'value' => (float) $tier['value']];
            }
        }

        return $reached;
    }

    public function isTargetMet(): bool
    {
        return $this->reachedTier() !== null;
    }

    /**
     * مكافأة شريحةٍ بعينها: قيمةٌ ثابتة لـ`fixed`، أو نسبةٌ **من عتبة الشريحة**
     * لـ`percentage`.
     *
     * تاسك 73: the percentage used to be taken from `achieved_amount`, which
     * made the bonus a commission on sales rather than a reward for reaching
     * an agreed target — whoever exactly hit the target was paid *less* than
     * whoever overshot it. A target of 20,000 at 10% is 2,000, for everyone
     * who reaches it. تاسك 105 keeps that rule per tier: 1000/1% and 2000/2%
     * with 2,900 achieved pay 2% of 2,000 = 40.
     *
     * @param  array{threshold: float|string, value: float|string}  $tier
     */
    public function tierBonus(array $tier): float
    {
        return match ($this->bonus_type) {
            IncentiveBonusTypeEnum::Fixed => (float) $tier['value'],
            IncentiveBonusTypeEnum::Percentage => round((float) $tier['threshold'] * (float) $tier['value'] / 100, 2),
        };
    }

    /**
     * المكافأة المستحقة الآن: مكافأة أعلى شريحة مبلوغة وحدها (لا تُجمع
     * الشرائح). وقبل بلوغ أدناها تُعرض مكافأة الأدنى — ما سيناله عند بلوغها.
     */
    public function bonusAmount(): float
    {
        return $this->tierBonus($this->reachedTier() ?? $this->tiers[0]);
    }
}
