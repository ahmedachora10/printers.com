<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خامة واحدة من خامات خدمة الفرع: منتجٌ من المخزون تستهلكه الخدمة، وكميةُ
 * استهلاكه لكل وحدة محاسَب عليها في السطر (قطعة لخدمةٍ بالقطعة، متر مربع لخدمةٍ
 * مسعّرة بالمتر). اعتمادُ فاتورة الخدمة يخصمها من المخزون واسترجاعُها يعيدها.
 *
 * @property float $qty_per_unit
 */
class BranchServiceMaterial extends Model
{
    protected $fillable = [
        'branch_service_id',
        'product_id',
        'qty_per_unit',
    ];

    protected $casts = [
        'qty_per_unit' => 'float',
    ];

    /** @return BelongsTo<BranchService, $this> */
    public function branchService(): BelongsTo
    {
        return $this->belongsTo(BranchService::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
