<?php

namespace App\Actions\BranchService;

use App\Models\BranchService;
use Illuminate\Support\Facades\DB;

/**
 * يضبط خامات المخزون لخدمة فرع على القائمة المرسلة بالضبط (تاسك 50): ما وصل
 * يُحدَّث أو يُنشأ، وما لم يصل يُحذف. الحذف هنا لا يمسّ حركات المخزون المكتوبة —
 * تلك جدول إدراج فقط، وما استُهلك بالأمس يبقى مسجَّلاً كما وقع.
 */
class SyncBranchServiceMaterialsAction
{
    /** @param list<array{product_id: int, qty_per_unit: float}> $materials */
    public function handle(BranchService $branchService, array $materials): void
    {
        DB::transaction(function () use ($branchService, $materials): void {
            $keptProductIds = [];

            foreach ($materials as $material) {
                $branchService->materials()->updateOrCreate(
                    ['product_id' => $material['product_id']],
                    ['qty_per_unit' => round((float) $material['qty_per_unit'], 2)],
                );

                $keptProductIds[] = $material['product_id'];
            }

            $branchService->materials()
                ->when($keptProductIds !== [], fn ($q) => $q->whereNotIn('product_id', $keptProductIds))
                ->delete();
        });
    }
}
