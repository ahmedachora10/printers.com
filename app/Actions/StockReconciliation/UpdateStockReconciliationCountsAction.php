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
    /** @param array<int, array{line_id: int, physical_qty: float}> $counts */
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

                $physicalQty = round((float) $count['physical_qty'], 2);

                $line->update([
                    'physical_qty' => $physicalQty,
                    'variance' => round($physicalQty - (float) $line->system_qty, 2),
                ]);
            }

            return $reconciliation->refresh();
        });
    }
}
