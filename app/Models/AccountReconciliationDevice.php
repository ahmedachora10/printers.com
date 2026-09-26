<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تاسك 121 — موازنة جهاز شبكة واحد؛ عدة أجهزة لنفس الطريقة مسموحة. */
class AccountReconciliationDevice extends Model
{
    protected $fillable = ['payment_method_id', 'device_label', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }
}
