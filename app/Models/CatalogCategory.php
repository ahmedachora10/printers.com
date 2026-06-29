<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CatalogCategory extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'name_ar',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile();
    }

    /** @return HasMany<CatalogSubcategory, $this> */
    public function subcategories(): HasMany
    {
        return $this->hasMany(CatalogSubcategory::class, 'category_id');
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
