<?php

namespace App\Models;

use App\Enums\LineAgentCommissionTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'branch_service_id',
        'service_name',
        'notes',
        'qty',
        'unit_price',
        'width_cm',
        'height_cm',
        'discount_pct',
        'subtotal',
        'commission_pct',
        'commission_amount',
        'is_tahazir',
        'tier_applied',
        'agent_id',
        'agent_commission_type',
        'agent_commission_value',
        'agent_commission_amount',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'commission_pct' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'is_tahazir' => 'boolean',
        'tier_applied' => 'integer',
        'agent_commission_type' => LineAgentCommissionTypeEnum::class,
        'agent_commission_value' => 'decimal:2',
        'agent_commission_amount' => 'decimal:2',
    ];

    /** @return BelongsTo<ServiceInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoice::class, 'invoice_id');
    }

    /** @return BelongsTo<BranchService, $this> */
    public function branchService(): BelongsTo
    {
        return $this->belongsTo(BranchService::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function lineAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
