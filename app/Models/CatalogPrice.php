<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogPrice extends Model
{
    protected $fillable = [
        'subcategory_id',
        'branch_id',
        'name',
        'min_price',
        'max_price',
        'base_price',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'subcategory_id' => 'integer',
        'branch_id' => 'integer',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'base_price' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The owning branch, or null for a catalogue-wide price. A branch row wins
     * over the general one carrying the same name (تاسك 47).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<CatalogSubcategory, $this> */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(CatalogSubcategory::class, 'subcategory_id');
    }

    /**
     * The rows one branch may read: its own prices plus every general one.
     * A null branch narrows to the general prices alone — that is what the
     * public catalogue shows before a branch is picked.
     */
    public function scopeForBranch($query, ?int $branchId)
    {
        $query->where(fn ($q) => $q
            ->whereNull('branch_id')
            ->when($branchId !== null, fn ($q) => $q->orWhere('branch_id', $branchId)));
    }

    public function scopeActive($query)
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
