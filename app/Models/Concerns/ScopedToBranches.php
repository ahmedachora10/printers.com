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
     * العامة + ما يشمل الفرع (أو أيّ فرعٍ من القائمة — يقيس به الطلبُ تعارضَ الأسماء).
     * null أو [] = الكل بلا تقييد (السوبر أدمن، أو نطاقٌ عامّ يصادم كل سجل).
     *
     * @param  Builder<$this>  $query
     * @param  int|list<int>|null  $branchIds
     */
    public function scopeVisibleToBranch(Builder $query, int|array|null $branchIds): void
    {
        if ($branchIds === null || $branchIds === []) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('branches')
            ->orWhereHas('branches', fn (Builder $b) => $b->whereKey($branchIds)));
    }

    /**
     * العامة النشطة + كل ما نطاقه الفرع وحده (نشطاً أو معطّلاً) — شاشة إدارة مدير الفرع (تاسك 240).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeManagedListFor(Builder $query, int $branchId): void
    {
        $query->visibleToBranch($branchId)
            ->where(fn (Builder $q) => $q->where('is_active', true)
                ->orWhere(fn (Builder $own) => $own
                    ->whereHas('branches', fn (Builder $b) => $b->whereKey($branchId))
                    ->whereDoesntHave('branches', fn (Builder $b) => $b->whereKeyNot($branchId))));
    }

    /**
     * فلتر «النطاق» في شاشات السوبر أدمن: «global» = العامة وحدها، ورقمٌ = ما يشمل ذلك الفرع.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInBranchFilter(Builder $query, ?string $filter): void
    {
        $query->when(filled($filter), fn (Builder $q) => $filter === 'global'
            ? $q->whereDoesntHave('branches')
            : $q->whereHas('branches', fn (Builder $b) => $b->whereKey((int) $filter)));
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
        if ($branchIds === []) {
            return [];
        }

        $missing = array_diff($this->usedInBranchIds(), $branchIds);

        return $missing === [] ? [] : Branch::query()->whereKey($missing)->orderBy('name')->pluck('name')->all();
    }

    /** @return list<int> */
    abstract public function usedInBranchIds(): array;
}
