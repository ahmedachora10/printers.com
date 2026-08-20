<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * تاسك 47: the catalogue screens all answer the same three questions — which
 * rows may this user see, which branch do they write into, and do they get a
 * branch picker at all. Shared by the category, subcategory and price
 * controllers so the three never drift apart.
 *
 * A branch admin is pinned to their own branch and never chooses. The super
 * admin belongs to no branch: they see every row, filter with `?branch=`
 * (`general` for the shared rows), and write into whatever that filter names.
 */
trait ResolvesCatalogueScope
{
    protected function isCatalogueSuperAdmin(Request $request): bool
    {
        return (bool) $request->user()?->roleName?->isSuperAdmin();
    }

    /**
     * Narrow a catalogue query to what the user may see: a branch admin gets
     * their own rows plus the general ones, the super admin gets everything —
     * optionally narrowed by the branch filter on screen.
     *
     * Untyped on purpose: callers `tap()` this onto either an Eloquent builder
     * or a relation, and a relation hands the closure its underlying builder.
     *
     * @param  Builder<*>|\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $query
     */
    protected function scopeCatalogueQuery($query, Request $request): void
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->isCatalogueSuperAdmin($request)) {
            $query->forBranch($user->branch_id);

            return;
        }

        if (! $request->filled('branch')) {
            return;
        }

        $request->input('branch') === 'general'
            ? $query->whereNull('branch_id')
            : $query->where('branch_id', (int) $request->input('branch'));
    }

    /**
     * The branch a write (create, import) lands on. The branch admin's own
     * branch; for the super admin, the branch their filter names — and the
     * general rows when it names none, so an export and its re-import agree.
     */
    protected function catalogueWriteScope(Request $request): ?int
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->isCatalogueSuperAdmin($request)) {
            return $user->branch_id;
        }

        $branch = $request->input('branch');

        return $branch === null || $branch === '' || $branch === 'general' ? null : (int) $branch;
    }

    /**
     * Options for the super admin's branch picker, or null for everyone else —
     * a picker would be a lie for a user whose branch is pinned server-side.
     *
     * @return Collection<int, array{id: int, name: string}>|null
     */
    protected function cataloguePickerBranches(Request $request): ?Collection
    {
        if (! $this->isCatalogueSuperAdmin($request)) {
            return null;
        }

        return Branch::query()->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name])
            ->values();
    }
}
