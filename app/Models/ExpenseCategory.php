<?php

namespace App\Models;

use App\Models\Concerns\ScopedToBranches;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    /** الفروع (تاسك 238): فرعٌ أو عدّة فروع، وبلا فروع = عامة يراها كل فرع. */
    use ScopedToBranches;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return list<int> */
    public function usedInBranchIds(): array
    {
        return array_map('intval', $this->expenses()->distinct()->pluck('branch_id')->all());
    }

    /**
     * خيارات القوائم في المصروفات وتقريرها. حين يرى المستخدم كل الفروع
     * (`$branchId === null`) يُلحق أسماء فروع الفئة بها، فلا تلتبس «كهرباء» فرعين،
     * وتصفّي النافذةُ بالفرع المختار عبر branchIds (فارغة = عامة).
     *
     * @return Collection<int, array{id: int, name: string, branchIds: list<int>}>
     */
    public static function activeOptionsFor(?int $branchId): Collection
    {
        return self::query()
            ->with('branches:id,name')
            ->visibleToBranch($branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (self $category) => [
                'id' => $category->id,
                'name' => $branchId === null && $category->branches->isNotEmpty()
                    ? "{$category->name} — {$category->branches->pluck('name')->join('، ')}"
                    : $category->name,
                'branchIds' => $category->branchIds(),
            ]);
    }
}
