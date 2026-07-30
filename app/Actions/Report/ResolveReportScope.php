<?php

namespace App\Actions\Report;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Fold role restrictions into the raw request filters for any report.
 *
 * Branch-admins and accountants are pinned to their own branch; only
 * super-admin may pick a branch freely. The `$user->branchId` accessor already
 * resolves the branch-admin `owner_id` indirection, so no special-casing here.
 *
 * An unfiltered report shows TODAY only — every report opens on the current day
 * and the user widens the range from the filter modal. `from`/`to` are therefore
 * never null, so callers can rely on a bounded window when zero-filling days.
 */
class ResolveReportScope
{
    /**
     * @return array{isSuper: bool, branchId: ?int, from: Carbon, to: Carbon}
     */
    public function handle(Request $request): array
    {
        $actor = $request->user();
        $isSuper = $actor->roleName?->isSuperAdmin() ?? false;

        return [
            'isSuper' => $isSuper,
            'branchId' => $isSuper
                ? ($request->filled('branch') ? (int) $request->input('branch') : null)
                : $actor->branchId,
            'from' => $request->filled('from')
                ? Carbon::parse($request->input('from'))->startOfDay()
                : Carbon::today()->startOfDay(),
            'to' => $request->filled('to')
                ? Carbon::parse($request->input('to'))->endOfDay()
                : Carbon::today()->endOfDay(),
        ];
    }
}
