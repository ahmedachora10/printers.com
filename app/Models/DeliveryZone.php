<?php

namespace App\Models;

use App\Enums\DeliveryZoneTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تاسك 93 — شريحة سعر توصيل: حيٌّ باسمه أو مدىً بالكيلومترات.
 *
 * سعرُ الشريحة — كسائر أسعار النظام (تاسك 37) — **شاملٌ لضريبة القيمة المضافة**:
 * يُكتب كما يُقرأ على الفاتورة، وتُستخرج الضريبة من داخله لا تُضاف فوقه.
 */
class DeliveryZone extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'type',
        'name',
        'from_km',
        'to_km',
        'price',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'type' => DeliveryZoneTypeEnum::class,
        'from_km' => 'decimal:2',
        'to_km' => 'decimal:2',
        'price' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * شرائح فرعٍ بعينه؛ `null` (السوبر أدمن بلا فرع مختار) يعني الكل.
     *
     * @param  Builder<DeliveryZone>  $query
     */
    public function scopeForBranch($query, ?int $branchId)
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where('branch_id', $branchId);
    }

    /**
     * ترتيب العرض في المنتقي وفي شاشة الإدارة: الأحياء أولاً لأنها الطريق
     * الأغلب عملياً، ثم شرائح المسافة مرتّبةً تصاعدياً بحدّها الأدنى.
     *
     * @param  Builder<DeliveryZone>  $query
     */
    public function scopeOrdered($query)
    {
        return $query
            ->orderBy('type')          // 'area' قبل 'distance' أبجدياً — وهو المقصود
            ->orderBy('sort_order')
            ->orderByRaw('COALESCE(from_km, 0)')
            ->orderBy('name');
    }

    /** المدى مكتوبٌ للقراءة: «0 – 5 كم» أو «أكثر من 20 كم». */
    public function rangeLabel(): ?string
    {
        if ($this->type !== DeliveryZoneTypeEnum::Distance) {
            return null;
        }

        $from = rtrim(rtrim(number_format((float) $this->from_km, 2, '.', ''), '0'), '.');

        if ($this->to_km === null) {
            return "أكثر من {$from} كم";
        }

        $to = rtrim(rtrim(number_format((float) $this->to_km, 2, '.', ''), '0'), '.');

        return "{$from} – {$to} كم";
    }

    /**
     * هل تشمل هذه الشريحة مسافةً بعينها؟ الحدّ الأدنى شامل والأعلى غير شامل
     * (`from <= d < to`) حتى لا تقع المسافة 5 في شريحتين معاً؛ والشريحة
     * المفتوحة (`to_km === null`) تشمل كل ما فوق حدّها.
     *
     * صفّ الحيّ لا يُقاس بمسافة أبداً فيردّ `false` دائماً.
     */
    public function coversDistance(float $km): bool
    {
        if ($this->type !== DeliveryZoneTypeEnum::Distance || $this->from_km === null) {
            return false;
        }

        if ($km < (float) $this->from_km) {
            return false;
        }

        return $this->to_km === null || $km < (float) $this->to_km;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('shipping');
    }
}
