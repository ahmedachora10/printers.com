<?php

namespace App\Models;

use App\Enums\CustomerTierEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * حساب العميل في فرعٍ واحد. العميل نفسه (هويته) واحدٌ لكل الفروع، وما يخصّ كل
 * فرع هنا: نقاطه ومستواه وإنفاقه وحد ائتمانه ومندوبه وملاحظاته ونشاطه. لا يرى
 * فرعٌ حساب فرعٍ آخر.
 */
class CustomerBranch extends Pivot
{
    use LogsActivity;

    public const FIELDS = ['points_balance', 'cumulative_spend', 'tier', 'credit_limit', 'agent_id', 'notes', 'is_active'];

    protected $table = 'customer_branch';

    public $incrementing = true;

    protected $attributes = [
        'points_balance' => 0,
        'cumulative_spend' => 0,
        'tier' => 'none',
        'is_active' => true,
    ];

    protected $casts = [
        'tier' => CustomerTierEnum::class,
        'credit_limit' => 'decimal:2',
        'cumulative_spend' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        // Dirty-only: loyalty earns touch points/spend on every paid invoice —
        // logging full snapshots each time would drown the CRM timeline.
        return LogOptions::defaults()
            ->logOnly(self::FIELDS)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('customers');
    }

    /** حساب العميل في الفرع مقفلاً للكتابة — كل حركة نقاطٍ تمرّ منه. */
    public static function lockFor(int $customerId, int $branchId): ?self
    {
        return self::query()
            ->where('customer_id', $customerId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * حركة ولاءٍ على هذا الحساب، برصيده بعد تحديثه.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): LoyaltyTransaction
    {
        return LoyaltyTransaction::create([
            ...$attributes,
            'customer_id' => $this->customer_id,
            'branch_id' => $this->branch_id,
            'balance_after' => (int) $this->points_balance,
        ]);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
