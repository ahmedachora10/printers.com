<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * تاسك 170 — طلب توصيلٍ واحد على فاتورة خدمات: سائقه وعنوانه وشريحته وقيمته.
 * مجموع `fee` لطلبات الفاتورة هو `service_invoices.shipping_fee`.
 */
class ServiceInvoiceShipment extends Model
{
    protected $fillable = [
        'provider_id',
        'zone_id',
        'distance_km',
        'customer_address_id',
        'address',
        'fee',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'fee' => 'decimal:2',
    ];

    /** @return BelongsTo<ServiceInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    /** @return BelongsTo<DeliveryProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'provider_id');
    }

    /** @return BelongsTo<DeliveryZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }

    /**
     * تاسك 111 — تسوية أجر السائق السارية لهذا الطلب: مصروفٌ يحمل مزوّد التوصيل
     * (المحذوف ناعماً = ملغاة).
     *
     * @return HasOne<Expense, $this>
     */
    public function settlement(): HasOne
    {
        return $this->hasOne(Expense::class)->whereNotNull('delivery_provider_id');
    }
}
