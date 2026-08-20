<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CatalogSubcategory extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'branch_id',
        'name_ar',
        'category_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'category_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile();
    }

    /**
     * The owning branch, or null for a catalogue-wide subcategory (تاسك 47).
     * A branch may hang its own subcategory under a general category.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<CatalogCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    /** @return HasMany<CatalogPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(CatalogPrice::class, 'subcategory_id');
    }

    /**
     * The rows one branch may read: its own plus every general one. A null
     * branch narrows to the general rows alone (تاسك 47). The tree is additive
     * — a branch adds to what it inherits and never hides it; only prices
     * override, and they override by name.
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
        $query->orderBy('sort_order')->orderBy('name_ar');
    }
}
