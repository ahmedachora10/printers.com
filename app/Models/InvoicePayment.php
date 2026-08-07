<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * دفعة واحدة على فاتورة (عربون أو دفعة لاحقة).
 *
 * الجدول للإضافة فقط، على نمط commission_ledger: لا يُعدَّل صف ولا يُحذف بعد
 * إدراجه. تصحيح دفعة خاطئة يكون بإدراج دفعة سالبة مقابلة عبر
 * RecordInvoicePaymentAction، لا بـ UPDATE.
 */
class InvoicePayment extends Model
{
    protected $fillable = [
        'invoice_id',
        'invoice_type',
        'branch_id',
        'payment_method_id',
        'amount',
        'paid_at',
        'recorded_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function invoice(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'invoice_type', 'invoice_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
