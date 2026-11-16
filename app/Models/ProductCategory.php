<?php

namespace App\Models;

use App\Models\Concerns\ScopedToBranches;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory;

    /** تاسك 237: فرعٌ أو عدّة فروع، وبلا فروع = عامة يراها كل فرع. */
    use ScopedToBranches;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /** @return list<int> */
    public function usedInBranchIds(): array
    {
        return array_map('intval', $this->products()->distinct()->pluck('branch_id')->all());
    }

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
