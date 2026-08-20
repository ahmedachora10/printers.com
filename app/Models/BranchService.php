<?php

namespace App\Models;

use App\Enums\ServicePricingTypeEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BranchService extends Pivot
{
    protected $table = 'branch_services';

    protected $fillable = [
        'branch_id',
        'service_template_id',
        'base_commission_pct',
        'max_discount_pct',
        'pricing_type',
        'price_per_sqm',
        'agent_commission_per_sqm',
        'note_examples',
        'is_tahazir',
        'has_materials',
        'materials_cost',
        'is_active',
    ];

    protected $casts = [
        'base_commission_pct' => 'decimal:2',
        'max_discount_pct' => 'decimal:2',
        'pricing_type' => ServicePricingTypeEnum::class,
        'price_per_sqm' => 'decimal:2',
        'agent_commission_per_sqm' => 'decimal:2',
        'note_examples' => 'array',
        'is_tahazir' => 'boolean',
        'has_materials' => 'boolean',
        'materials_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ServiceTemplate, $this> */
    public function serviceTemplate(): BelongsTo
    {
        return $this->belongsTo(ServiceTemplate::class);
    }

    // ملاحظة لكل علاقة hasMany هنا: هذا الموديل Pivot، و`AsPivot::getForeignKey()`
    // يعيد خاصية الـpivot الفارغة لا `branch_service_id` — فالمفتاح يُسمّى صراحةً.

    /** @return HasMany<UserService, $this> */
    public function userCommissions(): HasMany
    {
        return $this->hasMany(UserService::class, 'branch_service_id');
    }

    /**
     * خامات المخزون التي تستهلكها الخدمة (تاسك 50). منفصلة تماماً عن
     * `materials_cost` — ذاك رقم محاسبي يُخصم من أساس عمولة الموظف (تاسك 7)،
     * وهذه حركاتٌ فعلية على المخزون. قد تُعرَّف إحداهما دون الأخرى.
     *
     * @return HasMany<BranchServiceMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(BranchServiceMaterial::class, 'branch_service_id');
    }
}
