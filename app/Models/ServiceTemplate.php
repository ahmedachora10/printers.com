<?php

namespace App\Models;

use Database\Factories\ServiceTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceTemplate extends Model
{
    /** @use HasFactory<ServiceTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * ترتيب العرض اليدوي ثم الاسم — نفس نطاق `CatalogCategory::scopeOrdered`
     * حرفياً (تاسك 82). يسري حيث تُقرأ الخدمات كلها لا في شاشة الإدارة وحدها.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * الفرع المالك للخدمة، أو null للخدمة العامة المتاحة لكل الفروع (تاسك 45).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * الخدمات التي يجوز لهذا الفرع ربطها: العامة وما أنشأه هو. تمرير null
     * (السوبر أدمن) يرفع القيد فيرى الكل.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAvailableToBranch(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('branch_id')
            ->orWhere('branch_id', $branchId));
    }

    /** @return BelongsToMany<Branch, $this, BranchService, 'pivot'> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_services')
            ->using(BranchService::class)
            ->withPivot(['id', 'base_commission_pct', 'max_discount_pct', 'max_selling_price', 'min_selling_price', 'pricing_type', 'price_per_sqm', 'agent_commission_per_sqm', 'note_examples', 'is_tahazir', 'has_materials', 'materials_cost', 'is_active'])
            ->withTimestamps();
    }
}
