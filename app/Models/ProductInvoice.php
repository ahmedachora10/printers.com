<?php

namespace App\Models;

use App\Enums\InvoiceStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProductInvoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

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
        'points_redeemed',
        'points_discount',
        'vat_pct',
        'vat_amount',
        'total_amount',
        'status',
        'paid_at',
        'attachment_path',
    ];

    protected $casts = [
        'status' => InvoiceStatusEnum::class,
        'subtotal' => 'decimal:2',
        'tier_discount_pct' => 'decimal:2',
        'tier_discount_amount' => 'decimal:2',
        'coupon_discount' => 'decimal:2',
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

    /** @return BelongsTo<Branch, self> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, self> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Customer, self> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<PaymentMethod, self> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return HasMany<ProductInvoiceLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductInvoiceLine::class, 'invoice_id');
    }
}
