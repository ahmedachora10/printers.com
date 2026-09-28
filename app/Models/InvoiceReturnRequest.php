<?php

namespace App\Models;

use App\Enums\ReturnRequestStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تاسك 135 — طلب استرجاع فاتورة خدمةٍ حُصِّل منها مبلغ. الموظف يرفعه،
 * والمعتمد يختار طريقة الردّ فيُنفَّذ الاسترجاع القائم (ReturnServiceInvoiceAction).
 */
class InvoiceReturnRequest extends Model
{
    protected $fillable = [
        'service_invoice_id',
        'branch_id',
        'requested_by',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'rejection_reason',
        'refund_id',
    ];

    protected $casts = [
        'status' => ReturnRequestStatusEnum::class,
        'decided_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
