<?php

namespace App\Actions\StockReconciliation;

use App\Models\StockReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves physical count entries on an in-progress reconciliation. Variance is
 * recomputed against the system snapshot taken when the count started, not
 * against live stock — sales made mid-count are settled by the snapshot.
 */
class UpdateStockReconciliationCountsAction
{
    /** @param array<int, array{line_id: int, physical_qty: int}> $counts */
    public function handle(StockReconciliation $reconciliation, array $counts): StockReconciliation
    {
        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'counts' => 'لا يمكن تعديل جرد مكتمل.',
            ]);
        }

        return DB::transaction(function () use ($reconciliation, $counts) {
            $lines = $reconciliation->lines()->get()->keyBy('id');

            foreach ($counts as $count) {
                $line = $lines->get($count['line_id']);

                if ($line === null) {
                    throw ValidationException::withMessages([
                        'counts' => 'بند غير صالح لهذا الجرد.',
                    ]);
                }

                $physicalQty = (int) $count['physical_qty'];

                $line->update([
                    'physical_qty' => $physicalQty,
                    'variance' => $physicalQty - $line->system_qty,
                ]);
            }

            return $reconciliation->refresh();
        });
    }
}
