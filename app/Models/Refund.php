<?php

namespace App\Models;

use App\Enums\InvoiceTypeEnum;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'user_id',
        'source_type',
        'invoice_id',
        'invoice_type',
        'amount',
        'shipping_refunded',
        'payment_method_id',
        'reason',
        'stock_reversed',
    ];

    protected $casts = [
        'source_type' => InvoiceTypeEnum::class,
        'amount' => 'decimal:2',
        'shipping_refunded' => 'decimal:2',
        'stock_reversed' => 'boolean',
    ];

    /**
     * تاسك 150 — رقم إشعار المرتجع CN-{الفرع}-{التسلسل} بنمط أرقام الفواتير،
     * يُختم عند الإنشاء أيّاً كان طريقه (الإجراء، الاسترجاع الكامل، المصانع).
     */
    protected static function booted(): void
    {
        static::creating(function (Refund $refund) {
            $seq = static::withTrashed()->where('branch_id', $refund->branch_id)->lockForUpdate()->count() + 1;
            $refund->notice_number = sprintf('CN-%03d-%05d', $refund->branch_id, $seq);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('refunds');
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

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return MorphTo<Model, $this> */
    public function invoice(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'invoice_type', 'invoice_id');
    }
}
