<?php

namespace App\Actions\AccountReconciliation;

use App\Models\AccountReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** تاسك 121 — حفظ موازنات الأجهزة ليومٍ وفرع. المعتمَد مقفل. */
class SaveAccountReconciliationAction
{
    /**
     * @param  list<array{payment_method_id: int, device_label: string, amount: numeric-string|float}>  $devices
     */
    public function handle(int $branchId, string $date, array $devices, ?string $notes, User $actor): AccountReconciliation
    {
        return DB::transaction(function () use ($branchId, $date, $devices, $notes, $actor) {
            // withTrashed: القيد الفريد لا يرى الحذف الناعم (نفس SettlementFileController).
            $reconciliation = AccountReconciliation::withTrashed()->lockForUpdate()->firstOrCreate(
                ['branch_id' => $branchId, 'date' => $date],
                ['created_by' => $actor->id],
            );
            $reconciliation->restore();

            if ($reconciliation->isApproved()) {
                throw ValidationException::withMessages(['devices' => 'المطابقة معتمدة — ألغِ الاعتماد أولاً']);
            }

            $reconciliation->devices()->delete();
            $reconciliation->devices()->createMany($devices);
            $reconciliation->update([
                'devices_total' => round(array_sum(array_map(fn ($d) => (float) $d['amount'], $devices)), 2),
                'notes' => $notes,
            ]);

            return $reconciliation;
        });
    }
}
