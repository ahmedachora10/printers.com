<?php

namespace App\Models;

use App\Enums\CouponDiscountTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'branch_id',
        'discount_type',
        'discount_value',
        'capacity',
        'used_count',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'discount_type'  => CouponDiscountTypeEnum::class,
        'discount_value' => 'decimal:2',
        'is_active'      => 'boolean',
        'expires_at'     => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
