<?php

namespace App\Actions\StockReconciliation;

use App\Models\Product;
use App\Models\StockReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a stock count for a branch by snapshotting every active product's
 * system quantity into reconciliation lines. Physical quantities start equal
 * to the snapshot (variance 0) so the counter only edits products that differ.
 * Only one reconciliation may be in progress per branch — concurrent counts
 * against the same moving ledger would post conflicting adjustments.
 */
class StartStockReconciliationAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): StockReconciliation
    {
        $user = auth()->user();
        $branchId = $user->branchId ?? $data['branch_id'] ?? null;

        if ($branchId === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'يجب تحديد الفرع لبدء الجرد.',
            ]);
        }

        if (StockReconciliation::where('branch_id', $branchId)->whereNull('completed_at')->exists()) {
            throw ValidationException::withMessages([
                'branch_id' => 'يوجد جرد قيد التنفيذ لهذا الفرع بالفعل.',
            ]);
        }

        $products = Product::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->get(['id', 'current_stock']);

        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'branch_id' => 'لا توجد منتجات نشطة في هذا الفرع لجردها.',
            ]);
        }

        return DB::transaction(function () use ($data, $branchId, $user, $products) {
            $reconciliation = StockReconciliation::create([
                'branch_id' => $branchId,
                'initiated_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $reconciliation->lines()->createMany(
                $products->map(fn (Product $product) => [
                    'product_id' => $product->id,
                    'system_qty' => $product->current_stock,
                    'physical_qty' => $product->current_stock,
                    'variance' => 0,
                ])->all(),
            );

            return $reconciliation;
        });
    }
}
