<?php

namespace App\Actions\AccountReconciliation;

use App\Models\AccountReconciliation;
use App\Models\NetworkDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** تاسك 121 — حفظ موازنات الأجهزة ليومٍ وفرع. المعتمَد مقفل. */
class SaveAccountReconciliationAction
{
    /**
     * @param  list<array{network_device_id: int, card_type_id: int, amount: numeric-string|float}>  $devices
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

            // تاسك 146: طريقة الدفع ورقم الجهاز لقطةٌ من الجهاز لحظة الحفظ.
            $known = NetworkDevice::query()->whereKey(array_column($devices, 'network_device_id'))->get()->keyBy('id');

            // ponytail: صفوف ما قبل تاسك 146 (بلا جهاز) تبقى كما هي ولا تُحرَّر من الواجهة؛
            // ترحيلٌ يربطها بجهاز إن احتاج العميل تصحيح يومٍ قديم.
            $reconciliation->devices()->whereNotNull('network_device_id')->delete();
            $reconciliation->devices()->createMany(array_map(fn (array $d) => [
                ...$d,
                'payment_method_id' => $known[$d['network_device_id']]->payment_method_id,
                'device_label' => $known[$d['network_device_id']]->number,
            ], $devices));
            $reconciliation->update([
                'devices_total' => round((float) $reconciliation->devices()->sum('amount'), 2),
                'notes' => $notes,
            ]);

            return $reconciliation;
        });
    }
}
