<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** تاسك 146 — جهاز شبكة (نقطة بيع) لفرع؛ واحدٌ افتراضي لكل فرع يُعبَّأ في المطابقة الجديدة. */
class NetworkDevice extends Model
{
    use SoftDeletes;

    protected $fillable = ['branch_id', 'payment_method_id', 'name', 'number', 'is_default', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }
}
