<?php

namespace App\Actions\AccountReconciliation;

use App\Models\AccountReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 121 — الاعتماد يجمّد أرقام النظام على الصف: فاتورةٌ تُصحَّح بعده لا تغيّر
 * نتيجة يومٍ أُقفل. وإلغاؤه يعيدها null فتُحسب حيّةً من جديد.
 */
class ApproveAccountReconciliationAction
{
    public function __construct(private readonly BuildReconciliationFiguresAction $figures) {}

    public function handle(AccountReconciliation $reconciliation, User $actor): void
    {
        DB::transaction(function () use ($reconciliation, $actor) {
            $figures = $this->figures->handle($reconciliation->branch_id, $reconciliation->date);

            $reconciliation->update([
                'system_net' => $figures['systemNet'],
                'auto_total' => $figures['autoTotal'],
                'auto_breakdown' => $figures['autoBreakdown'],
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
        });
    }

    public function revert(AccountReconciliation $reconciliation): void
    {
        $reconciliation->update([
            'system_net' => null,
            'auto_total' => null,
            'auto_breakdown' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }
}
