<?php

namespace App\Models;

use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * فلاتر الشاشة — تقرؤها الشاشة والتصدير معاً.
     *
     * @param  Builder<$this>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilteredBy(Builder $query, array $filters): void
    {
        $query
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('is_active', (bool) $filters['status']));
    }
}
