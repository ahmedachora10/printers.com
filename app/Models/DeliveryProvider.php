<?php

namespace App\Models;

use App\Enums\DeliveryProviderTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('shipping');
    }

    /**
     * مزوّدو التوصيل النشطون في الفرع (تاسك 93).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function posOptions(?int $branchId): Collection
    {
        if ($branchId === null) {
            return collect();
        }

        return DeliveryProvider::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (DeliveryProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'typeLabel' => $provider->type->label(),
            ]);
    }
}
