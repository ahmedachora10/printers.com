<?php

namespace App\Models;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'branch_id',
        'customer_type',
        'company_name',
        'tax_number',
        'credit_limit',
        'agent_id',
        'points_balance',
        'cumulative_spend',
        'tier',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'customer_type' => CustomerTypeEnum::class,
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
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('customers');
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

    /**
     * Shape this customer for the POS customer picker, including loyalty eligibility.
     * Shared by the POS create screens and the async customer-search endpoint.
     *
     * @return array<string, mixed>
     */
    public function toPosArray(?LoyaltyConfig $loyalty, bool $loyaltyActive): array
    {
        $eligible = $loyaltyActive
            && $this->customer_type === CustomerTypeEnum::Individual
            && $this->agent_id === null;

        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'taxNumber' => $this->tax_number,
            'agentId' => $this->agent_id,
            'pointsBalance' => (int) $this->points_balance,
            'tier' => $this->tier->value,
            'tierLabel' => $this->tier->label(),
            'tierDiscountPct' => $eligible ? $loyalty->discountPctForTier($this->tier) : 0.0,
            'loyaltyEligible' => $eligible,
        ];
    }

    /** @return HasMany<ProductInvoice, $this> */
    public function productInvoices(): HasMany
    {
        return $this->hasMany(ProductInvoice::class, 'customer_id');
    }

    /** @return HasMany<ServiceInvoice, $this> */
    public function serviceInvoices(): HasMany
    {
        return $this->hasMany(ServiceInvoice::class, 'customer_id');
    }

    /** @return HasMany<LoyaltyTransaction, $this> */
    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
