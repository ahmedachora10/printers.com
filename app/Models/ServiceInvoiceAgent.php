<?php

namespace App\Models;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent attached to a service invoice, with a snapshot of the terms applied
 * at invoice time and its own rebate settlement (agent_payment_id). An invoice
 * may have several of these — one per agent sharing the rebate.
 */
class ServiceInvoiceAgent extends Model
{
    protected $table = 'service_invoice_agent';

    protected $fillable = [
        'service_invoice_id',
        'agent_id',
        'discount_mode',
        'discount_type',
        'rate',
        'discount_amount',
        'rebate_amount',
        'line_commission_amount',
        'agent_payment_id',
    ];

    protected $casts = [
        'discount_mode' => AgentDiscountModeEnum::class,
        'discount_type' => AgentDiscountTypeEnum::class,
        'rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'rebate_amount' => 'decimal:2',
        'line_commission_amount' => 'decimal:2',
    ];

    /** @return BelongsTo<ServiceInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
