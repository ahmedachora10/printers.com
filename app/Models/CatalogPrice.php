<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogPrice extends Model
{
    protected $fillable = [
        'subcategory_id',
        'name',
        'min_price',
        'max_price',
        'base_price',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'subcategory_id' => 'integer',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'base_price' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<CatalogSubcategory, $this> */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(CatalogSubcategory::class, 'subcategory_id');
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
