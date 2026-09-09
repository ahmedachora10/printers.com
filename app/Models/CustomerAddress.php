<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تاسك 93 — عنوانٌ في دفتر عناوين العميل، يحمل شريحة توصيله.
 *
 * حملُ `delivery_zone_id` هنا هو جوهر «ربط العنوان بمسافة التوصيل»: اختيار
 * العنوان في نقطة البيع يملأ السعر وحده، فلا يقدّر الكاشير مسافةً بالحدس.
 */
class CustomerAddress extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'label',
        'address',
        'location_url',
        'delivery_zone_id',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /** ما يُعرض في المنتقي: «المكتب — طريق الملك فهد». */
    public function displayLabel(): string
    {
        return $this->label ? "{$this->label} — {$this->address}" : (string) $this->address;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->useLogName('customer');
    }
}
