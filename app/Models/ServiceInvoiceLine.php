<?php

namespace App\Models;

use App\Enums\LineAgentCommissionTypeEnum;
use App\Enums\ServicePricingTypeEnum;
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
        'unit_price_basis',
        'discount_pct',
        'subtotal',
        'commission_pct',
        'commission_amount',
        'materials_cost',
        'materials_total',
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
        'unit_price_basis' => ServicePricingTypeEnum::class,
        'discount_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'commission_pct' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'materials_cost' => 'decimal:2',
        'materials_total' => 'decimal:2',
        'is_tahazir' => 'boolean',
        'tier_applied' => 'integer',
        'agent_commission_type' => LineAgentCommissionTypeEnum::class,
        'agent_commission_value' => 'decimal:2',
        'agent_commission_amount' => 'decimal:2',
    ];

    /**
     * هل يقيس unit_price في هذا السطر سعرَ مترٍ مربع؟ صحيحٌ للأسطر المسعّرة بالمتر
     * بعد أن صار سعرها سعر متر (unit_price_basis = sqm)، وخاطئٌ للأسطر القديمة
     * التي حفظت سعر القطعة ولا عمود يميّزها (null) — فتُعرض وتُقرأ كما حُفظت.
     */
    public function isPricedPerSqm(): bool
    {
        return $this->unit_price_basis === ServicePricingTypeEnum::Sqm;
    }

    /** مساحة القطعة الواحدة بالمتر المربع من المقاس المحفوظ — صفر بلا مقاس. */
    public function areaSqm(): float
    {
        if ($this->width_cm === null || $this->height_cm === null) {
            return 0.0;
        }

        return ((float) $this->width_cm / 100) * ((float) $this->height_cm / 100);
    }

    /**
     * سعر المتر لهذا السطر كما تفهمه نقطة البيع اليوم. السطر الحديث يحمله كما هو،
     * والسطر القديم يحمل سعر القطعة فيُقسم على مساحته — كي لا يُقرأ سعرُ قطعةٍ
     * سعرَ مترٍ فيتضاعف إجمالي الفاتورة لمجرد فتحها للتعديل.
     */
    public function unitPricePerSqm(): float
    {
        $price = (float) $this->unit_price;

        if ($this->isPricedPerSqm()) {
            return $price;
        }

        $area = $this->areaSqm();

        return $area > 0 ? round($price / $area, 2) : $price;
    }

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
