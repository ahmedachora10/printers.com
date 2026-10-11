<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'requested_by',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'fee' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    /**
     * طلبات نقطة البيع (بلا `requested_by`) وما اعتمده المحاسب مما طُلب بعد الاعتماد.
     *
     * @param  Builder<self>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull($this->qualifyColumn('requested_by'))->orWhereNotNull($this->qualifyColumn('approved_at')));
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('requested_by'))->whereNull($this->qualifyColumn('approved_at'));
    }

    public function isPending(): bool
    {
        return $this->requested_by !== null && $this->approved_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

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
