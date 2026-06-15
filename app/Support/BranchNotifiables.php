<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves which users should be notified about a branch-scoped event.
 *
 * The branch-admin is the branch's `owner` (branches.owner_id) — their own
 * `users.branch_id` column is not set to the branch they manage — while
 * accountants/employees are linked through `users.branch_id`. This helper
 * reconciles both so callers can `Notification::send()` to the right people.
 */
class BranchNotifiables
{
    /**
     * @param  array<int, string>  $roleNames  e.g. ['branch-admin', 'accountant']
     * @return Collection<int, User>
     */
    public static function forBranch(int $branchId, array $roleNames): Collection
    {
        /** @var Collection<int, User> $users */
        $users = collect();

        if (in_array('branch-admin', $roleNames, true)) {
            $owner = Branch::find($branchId)?->owner;

            if ($owner && $owner->hasRole('branch-admin')) {
                $users->push($owner);
            }
        }

        $branchRoles = array_values(array_diff($roleNames, ['branch-admin']));

        if ($branchRoles !== []) {
            $users = $users->concat(
                User::query()
                    ->where('branch_id', $branchId)
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', $branchRoles))
                    ->get()
            );
        }

        return $users->unique('id')->values();
    }
}
