<?php

namespace App\Models;

use App\Enums\DeliveryStatusEnum;
use App\Enums\InvoiceStatusEnum;
use App\Models\Concerns\HasReceiptMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ServiceInvoice extends Model implements HasMedia
{
    use HasReceiptMedia, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'branch_id',
        'user_id',
        'customer_id',
        'coupon_id',
        'payment_method_id',
        'subtotal',
        'tier_discount_pct',
        'tier_discount_amount',
        'coupon_discount',
        'agent_discount',
        'points_redeemed',
        'points_discount',
        'vat_pct',
        'vat_amount',
        'total_amount',
        'employee_commission',
        'notes',
        'delivery_at',
        'status',
        'paid_at',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'status' => InvoiceStatusEnum::class,
        'subtotal' => 'decimal:2',
        'tier_discount_pct' => 'decimal:2',
        'tier_discount_amount' => 'decimal:2',
        'coupon_discount' => 'decimal:2',
        'agent_discount' => 'decimal:2',
        'points_redeemed' => 'integer',
        'points_discount' => 'decimal:2',
        'vat_pct' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'employee_commission' => 'decimal:2',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'delivery_at' => 'datetime',
    ];

    /**
     * حالة موعد التسليم — متأخر / اليوم / قادم، أو null إن لم يُحدَّد موعد أو كانت
     * الفاتورة ملغاة أو مرتجعة (لم يعد لها عمل يُسلَّم).
     */
    public function deliveryStatus(): ?DeliveryStatusEnum
    {
        return DeliveryStatusEnum::forInvoice($this->delivery_at, $this->status);
    }

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

    /**
     * The reviewer who cancelled the invoice — shown to the employee beside the
     * cancellation reason.
     *
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The agents attached to this invoice (shared rebate), each carrying its own
     * snapshot of terms and rebate settlement on the pivot.
     *
     * @return HasMany<ServiceInvoiceAgent, $this>
     */
    public function invoiceAgents(): HasMany
    {
        return $this->hasMany(ServiceInvoiceAgent::class, 'service_invoice_id');
    }

    /** @return BelongsToMany<Agent, $this> */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'service_invoice_agent', 'service_invoice_id', 'agent_id')
            ->withPivot(['discount_mode', 'discount_type', 'rate', 'discount_amount', 'rebate_amount', 'line_commission_amount', 'agent_payment_id']);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return HasMany<ServiceInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ServiceInvoiceLine::class, 'invoice_id');
    }

    /** @return MorphMany<Refund, $this> */
    public function refunds(): MorphMany
    {
        return $this->morphMany(Refund::class, 'invoice');
    }
}
