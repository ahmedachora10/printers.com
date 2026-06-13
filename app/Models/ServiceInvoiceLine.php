<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'branch_service_id',
        'service_name',
        'qty',
        'unit_price',
        'discount_pct',
        'subtotal',
        'commission_pct',
        'commission_amount',
        'tier_applied',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'commission_pct' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'tier_applied' => 'integer',
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
}
