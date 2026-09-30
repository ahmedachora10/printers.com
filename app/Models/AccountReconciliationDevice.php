<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تاسك 121 — موازنة جهاز شبكة واحد.
 * تاسك 146: الصفّ = جهاز + نوع بطاقة + مبلغ؛ payment_method_id و device_label (رقم الجهاز)
 * لقطةٌ من الجهاز لحظة الحفظ. الصفوف الأقدم بلا جهاز ولا نوع.
 */
class AccountReconciliationDevice extends Model
{
    protected $fillable = ['payment_method_id', 'network_device_id', 'card_type_id', 'device_label', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }
}
