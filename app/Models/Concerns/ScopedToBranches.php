<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * تاسك 238 — بيانات يحدّد السوبر أدمن فروعها: فرعٌ أو عدّة فروع، وبلا فروع = عامة
 * لكل الفروع. مدير الفرع يملك ما نطاقه فرعه وحده.
 *
 * يستعمله: فئات المصروفات، فئات المنتجات (237)، طرق الدفع (242)، أنواع البطاقات (243).
 * الجدول الوسيط باسم Laravel الافتراضي: branch_{النموذج}.
 *
 * كل نموذج يعرّف usedInBranchIds(): الفروع التي استعملته — فلا يُحصر خارجها.
 */
trait ScopedToBranches
{
    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * العامة + ما يشمل الفرع. `$branchId === null` (السوبر أدمن) = الكل.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleToBranch(Builder $query, ?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('branches')
            ->orWhereHas('branches', fn (Builder $b) => $b->whereKey($branchId)));
    }

    /**
     * ما قد يتعارض مع سجلٍّ نطاقه $branchIds: العامة وما يشارك أيّ فرعٍ منه؛
     * وإن كان النطاق عامّاً فكلّ سجل.
     *
     * @param  Builder<$this>  $query
     * @param  list<int>  $branchIds
     */
    public function scopeOverlappingBranches(Builder $query, array $branchIds): void
    {
        if ($branchIds === []) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('branches')
            ->orWhereHas('branches', fn (Builder $b) => $b->whereKey($branchIds)));
    }

    /**
     * العامة النشطة + كل ما يملكه الفرع (نشطاً أو معطّلاً) — شاشة إدارة مدير الفرع (تاسك 240).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeManagedListFor(Builder $query, int $branchId): void
    {
        $query->visibleToBranch($branchId)
            ->where(fn (Builder $q) => $q->where('is_active', true)
                ->orWhere(fn (Builder $own) => $own->ownedBy($branchId)));
    }

    /**
     * ما نطاقه هذا الفرع وحده.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOwnedBy(Builder $query, int $branchId): void
    {
        $query->whereHas('branches', fn (Builder $b) => $b->whereKey($branchId))
            ->whereDoesntHave('branches', fn (Builder $b) => $b->whereKeyNot($branchId));
    }

    /** نطاقه هذا الفرع وحده — فيملكه مدير ذلك الفرع. */
    public function isOwnedBy(?int $branchId): bool
    {
        return $branchId !== null && $this->branchIds() === [$branchId];
    }

    /** @return list<int> */
    public function branchIds(): array
    {
        return array_map('intval', $this->branches->modelKeys());
    }

    /**
     * أسماء الفروع التي استعملته وتقع خارج $branchIds — فارغة إن كان النطاق عامّاً.
     *
     * @param  list<int>  $branchIds
     * @return list<string>
     */
    public function usedOutside(array $branchIds): array
    {
        if ($branchIds === [] || ! $this->exists) {
            return [];
        }

        $missing = array_diff($this->usedInBranchIds(), $branchIds);

        return $missing === [] ? [] : Branch::query()->whereKey($missing)->orderBy('name')->pluck('name')->all();
    }

    /** @return list<int> */
    abstract public function usedInBranchIds(): array;
}
