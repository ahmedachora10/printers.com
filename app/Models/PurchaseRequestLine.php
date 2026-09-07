<?php

namespace App\Models;

use Database\Factories\PurchaseRequestLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestLine extends Model
{
    /** @use HasFactory<PurchaseRequestLineFactory> */
    use HasFactory;

    protected $fillable = [
        'request_id',
        'product_id',
        'item_name',
        'qty',
        'is_sqm',
        'estimated_unit_cost',
        'notes',
        'approved_product_id',
        'approved_qty',
        'approved_is_sqm',
        'approved_unit_cost',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'is_sqm' => 'boolean',
        'estimated_unit_cost' => 'decimal:2',
        'approved_qty' => 'decimal:2',
        'approved_is_sqm' => 'boolean',
        'approved_unit_cost' => 'decimal:2',
    ];

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'request_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** المنتج الذي استقرّ عليه المعتمِد — قد يخالف ما اقترحه مقدّم الطلب. */
    public function approvedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'approved_product_id');
    }

    /**
     * ما يُبنى عليه الحساب: قرار المعتمِد إن اتُّخذ، وإلا ما طلبه الموظف.
     * تاسك 89 فصل الاثنين، فكل قارئ للرقم «الفعلي» يمرّ من هنا.
     */
    public function effectiveQty(): float
    {
        return (float) ($this->approved_qty ?? $this->qty);
    }

    public function effectiveUnitCost(): ?float
    {
        $cost = $this->approved_unit_cost ?? $this->estimated_unit_cost;

        return $cost !== null ? (float) $cost : null;
    }

    public function effectiveProductId(): ?int
    {
        return $this->approved_product_id ?? $this->product_id;
    }

    public function effectiveIsSqm(): bool
    {
        return (bool) ($this->approved_is_sqm ?? $this->is_sqm);
    }

    /** هل خالف القرارُ الطلبَ في شيء؟ يُبرز الفارق في شاشة العرض. */
    public function wasSettled(): bool
    {
        return $this->approved_qty !== null
            || $this->approved_unit_cost !== null
            || $this->approved_product_id !== null;
    }
}
