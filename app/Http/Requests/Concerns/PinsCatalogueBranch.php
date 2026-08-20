<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * تاسك 47: every catalogue row — category, subcategory, price — belongs either
 * to one branch or to the shared catalogue. The owner is decided on the server
 * and never read from the form: a branch admin always writes into their own
 * branch, and only the super admin may author a general row or one for another
 * branch. Restricting this on the screen alone would be walked around with a
 * hand-rolled request.
 *
 * Used by the Store requests; the Update ones never let a row change owner, so
 * they only borrow the uniqueness helpers.
 *
 * @mixin FormRequest
 */
trait PinsCatalogueBranch
{
    protected function pinBranchId(): void
    {
        $user = $this->user();

        if ($user?->roleName?->isBranchAdmin()) {
            $this->merge(['branch_id' => $user->branch_id]);

            return;
        }

        $this->merge(['branch_id' => $this->filled('branch_id') ? (int) $this->input('branch_id') : null]);
    }

    /** @return array<int, mixed> */
    protected function branchIdRules(): array
    {
        return ['nullable', 'integer', 'exists:branches,id'];
    }

    /**
     * Uniqueness inside one owner's rows alone. This is what prices use: a
     * branch price is *meant* to repeat the general name it overrides.
     *
     * The database cannot police the general rows on its own (MySQL and SQLite
     * compare NULLs as distinct), so this rule is their only guard.
     */
    protected function uniqueInBranchScope(string $table, string $column, ?int $branchId, ?int $ignoreId = null): Unique
    {
        return $this->ignoring(
            Rule::unique($table, $column)->where(fn ($q) => $branchId === null
                ? $q->whereNull('branch_id')
                : $q->where('branch_id', $branchId)),
            $ignoreId,
        );
    }

    /**
     * Uniqueness across everything the branch actually sees — its own rows and
     * the general ones it inherits. This is what the tree uses: the tree is
     * additive, so a branch category repeating a general name would simply
     * appear twice in that branch's list with no way to tell them apart.
     */
    protected function uniqueInBranchView(string $table, string $column, ?int $branchId, ?int $ignoreId = null): Unique
    {
        return $this->ignoring(
            Rule::unique($table, $column)->where(fn ($q) => $q
                ->where(fn ($q) => $q
                    ->whereNull('branch_id')
                    ->when($branchId !== null, fn ($q) => $q->orWhere('branch_id', $branchId)))),
            $ignoreId,
        );
    }

    private function ignoring(Unique $rule, ?int $ignoreId): Unique
    {
        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }
}
