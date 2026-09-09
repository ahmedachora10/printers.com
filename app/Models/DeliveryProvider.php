<?php

namespace App\Models;

use App\Enums\DeliveryProviderTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تاسك 93 — سائقٌ أو شركة توصيل يملكها فرع.
 *
 * لا مستحقّات ولا تسوية في هذه المرحلة: تُسجَّل قيمة التوصيل على الفاتورة
 * وتُنسب إلى المزوّد، ويبقى كشف المستحقّات تاسكاً مستقلاً — وإلا صار هذا
 * نظام مناديب ثانياً كاملاً (M26) داخل بندٍ واحد.
 */
class DeliveryProvider extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'type',
        'phone',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'type' => DeliveryProviderTypeEnum::class,
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * شرائح فرعٍ بعينه. لا صفوفَ عامّة هنا — لكل فرع مزوّدوه — فالنطاق شرطُ
     * مساواةٍ صريح لا `whereNull` كما في طرق الدفع.
     *
     * @param  Builder<DeliveryProvider>  $query
     */
    public function scopeForBranch($query, ?int $branchId)
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where('branch_id', $branchId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('shipping');
    }
}
