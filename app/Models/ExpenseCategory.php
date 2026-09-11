<?php

namespace App\Models;

use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** الفرع المالك — null = فئة عامة يراها كل فرع (تاسك 102). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * الفئات العامة + فئات الفرع نفسه. `$branchId === null` (السوبر أدمن) = الكل.
     * نسخة PaymentMethod::scopeVisibleToBranch.
     *
     * @param  Builder<ExpenseCategory>  $query
     */
    public function scopeVisibleToBranch($query, ?int $branchId)
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId));
    }

    /**
     * خيارات القوائم في المصروفات وتقريرها. حين يرى المستخدم كل الفروع
     * (`$branchId === null`) يُلحق اسم الفرع بفئته، فلا تلتبس «كهرباء» فرعين.
     *
     * @return Collection<int, array{id: int, name: string, branchId: ?int}>
     */
    public static function activeOptionsFor(?int $branchId): Collection
    {
        return self::query()
            ->with('branch:id,name')
            ->visibleToBranch($branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (self $category) => [
                'id' => $category->id,
                'name' => $branchId === null && $category->branch ? "{$category->name} — {$category->branch->name}" : $category->name,
                'branchId' => $category->branch_id,
            ]);
    }
}
