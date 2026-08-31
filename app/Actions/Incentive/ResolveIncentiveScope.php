<?php

namespace App\Actions\Incentive;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * المرشِّحات المشتركة بين شاشة الحوافز وتقريرها: الفرع والموظف والمدى والحالة.
 *
 * أسماء المعاملات هي أسماء صفحات التقارير نفسها (`branch`, `employee`, `from`,
 * `to`) كي تعمل مكوّنات التصفية الجاهزة — `useReportFilters` وشريط المدى — على
 * الشاشتين بلا ترجمةٍ بينهما. وحدود الفرع تُطوى هنا كما في ResolveReportScope:
 * السوبر أدمن وحده يختار فرعاً، وغيره مثبَّتٌ على فرعه.
 *
 * والمدى هنا **اختياري**: شاشة الحوافز قائمةُ إدارةٍ تُفتح على كل الفترات، بخلاف
 * التقارير التي تُفتح على اليوم. من أراد افتراضاً غير الفراغ مرّره صراحةً.
 */
class ResolveIncentiveScope
{
    /**
     * @return array{isSuper: bool, branchId: ?int, userId: ?int, from: ?Carbon, to: ?Carbon, status: ?string}
     */
    public function handle(Request $request, ?Carbon $defaultFrom = null, ?Carbon $defaultTo = null): array
    {
        $actor = $request->user();
        $isSuper = $actor->roleName?->isSuperAdmin() ?? false;

        return [
            'isSuper' => $isSuper,
            'branchId' => $isSuper
                ? ($request->filled('branch') ? (int) $request->input('branch') : null)
                : $actor->branchId,
            'userId' => $request->filled('employee') ? (int) $request->input('employee') : null,
            'from' => $request->filled('from')
                ? Carbon::parse($request->input('from'))->startOfDay()
                : $defaultFrom?->copy()->startOfDay(),
            'to' => $request->filled('to')
                ? Carbon::parse($request->input('to'))->endOfDay()
                : $defaultTo?->copy()->endOfDay(),
            'status' => $request->filled('status') ? (string) $request->input('status') : null,
        ];
    }
}
