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
 *
 * تاسك 124: `branchIds` — قائمة الفروع المختارة (فارغة = كل الفروع)، يقرؤها تقرير
 * المبيعات ولوحة التحكم (تاسك 128). و`branchId` يبقى كما هو لبقيّة التقارير: الفرع حين يكون واحداً، وإلا null.
 * ponytail: branchIds في المبيعات ولوحة التحكم وحدهما؛ يُنقل إليه تقريرٌ آخر حين يُطلب له.
 */
class ResolveReportScope
{
    /**
     * @return array{isSuper: bool, branchId: ?int, branchIds: list<int>, from: Carbon, to: Carbon}
     */
    public function handle(Request $request): array
    {
        $actor = $request->user();
        $isSuper = $actor->roleName?->isSuperAdmin() ?? false;
        // غير السوبر أدمن مثبَّتٌ على فرعه مهما أرسل.
        $branchIds = $isSuper
            ? array_values(array_unique(array_map('intval', array_filter(
                is_array($raw = $request->input('branch')) ? $raw : explode(',', (string) $raw),
                'is_numeric',
            ))))
            : array_filter([$actor->branchId]);

        return [
            'isSuper' => $isSuper,
            'branchId' => count($branchIds) === 1 ? $branchIds[0] : null,
            'branchIds' => array_values($branchIds),
            'from' => $request->filled('from')
                ? Carbon::parse($request->input('from'))->startOfDay()
                : Carbon::today()->startOfDay(),
            'to' => $request->filled('to')
                ? Carbon::parse($request->input('to'))->endOfDay()
                : Carbon::today()->endOfDay(),
        ];
    }
}
