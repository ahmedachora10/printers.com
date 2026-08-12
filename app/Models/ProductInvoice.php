<?php

namespace App\Models;

use App\Enums\InvoiceStatusEnum;
use App\Models\Concerns\HasInvoicePayments;
use App\Models\Concerns\HasReceiptMedia;
use App\Models\Concerns\HasVatBreakdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ProductInvoice extends Model implements HasMedia
{
    use HasInvoicePayments, HasReceiptMedia, HasVatBreakdown, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'branch_id',
        'user_id',
        'customer_id',
        'agent_id',
        'coupon_id',
        'payment_method_id',
        'subtotal',
        'tier_discount_pct',
        'tier_discount_amount',
        'coupon_discount',
        'agent_discount',
        'agent_rebate',
        'agent_payment_id',
        'points_redeemed',
        'points_discount',
        'vat_pct',
        'vat_amount',
        'total_amount',
        'notes',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'status' => InvoiceStatusEnum::class,
        'subtotal' => 'decimal:2',
        'tier_discount_pct' => 'decimal:2',
        'tier_discount_amount' => 'decimal:2',
        'coupon_discount' => 'decimal:2',
        'agent_discount' => 'decimal:2',
        'agent_rebate' => 'decimal:2',
        'points_redeemed' => 'integer',
        'points_discount' => 'decimal:2',
        'vat_pct' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('sales');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::RECEIPT_COLLECTION)
            ->singleFile()
            ->useDisk('local')
            ->acceptsMimeTypes(self::RECEIPT_MIME_TYPES);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return HasMany<ProductInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductInvoiceLine::class, 'invoice_id');
    }

    /** @return MorphMany<Refund, $this> */
    public function refunds(): MorphMany
    {
        return $this->morphMany(Refund::class, 'invoice');
    }
}
