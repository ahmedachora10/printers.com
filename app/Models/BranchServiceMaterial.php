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
 * @property float $waste_pct
 */
class BranchServiceMaterial extends Model
{
    protected $fillable = [
        'branch_service_id',
        'product_id',
        'qty_per_unit',
        'waste_pct',
    ];

    protected $casts = [
        'qty_per_unit' => 'float',
        'waste_pct' => 'float',
    ];

    /**
     * الكمية التي تُسحب فعلاً من المخزون مقابل وحدةٍ محاسَبٍ عليها من الخدمة:
     * الكمية المعرَّفة مضروبةً في كمية السطر، زائد الهالك. هذه هي الصيغة الوحيدة
     * في النظام — يقرؤها الخصمُ عند الاعتماد وفحصُ الكفاية ونقطةُ البيع معاً، فلا
     * تتباعد المعاينة عمّا يقع في المخزون.
     */
    public function consumptionFor(float $billableQty): float
    {
        return round($this->qty_per_unit * $billableQty * (1 + $this->waste_pct / 100), 2);
    }

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
