<?php

namespace App\Http\Controllers;

use App\Http\Requests\Report\StoreSettlementFileRequest;
use App\Models\AccountReconciliation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * تاسك 122 — رفع ملف موازنة الشبكة من تقرير المبيعات وتنزيله.
 *
 * الجمهور هو جمهور التقرير (مجموعة المسارات: مدير فرع، محاسب، سوبر أدمن)،
 * وغير السوبر أدمن مثبَّتٌ على فرعه — نفس قاعدة ResolveReportScope.
 * لا يُقرأ محتوى الملف: تخزينٌ وعرضٌ فقط، والقراءة الآلية تاسكٌ حين تُعرف الصيغة.
 */
class SettlementFileController extends Controller
{
    public function store(StoreSettlementFileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $branchId = $user->roleName->isSuperAdmin() ? $request->integer('branch') : $user->branchId;
        abort_unless($branchId, 422, 'اختر فرعاً واحداً');

        DB::transaction(function () use ($request, $user, $branchId) {
            // withTrashed: القيد الفريد لا يرى الحذف الناعم.
            $reconciliation = AccountReconciliation::withTrashed()->firstOrCreate(
                ['branch_id' => $branchId, 'date' => $request->date('date')->toDateString()],
                ['created_by' => $user->id],
            );
            $reconciliation->restore();
            $reconciliation->addMediaFromRequest('file')->toMediaCollection(AccountReconciliation::SETTLEMENT_FILE);
        });

        return back()->with('success', 'تم رفع ملف موازنة الشبكة');
    }

    public function show(Request $request, AccountReconciliation $reconciliation): Response
    {
        $user = $request->user();
        abort_unless($user->roleName->isSuperAdmin() || $user->branchId === $reconciliation->branch_id, 403);

        $media = $reconciliation->settlementFile();
        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }
}
