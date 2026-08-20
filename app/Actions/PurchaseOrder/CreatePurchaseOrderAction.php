<?php

namespace App\Actions\PurchaseOrder;

use App\Enums\PurchaseOrderStatusEnum;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class CreatePurchaseOrderAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): PurchaseOrder
    {
        $user = auth()->user();
        // The branch normally comes from the acting user; internal purchase
        // requests pass it explicitly so a super-admin can convert a request
        // that belongs to a branch they do not sit in.
        $branchId = $data['branch_id'] ?? $user->branchId;

        return DB::transaction(function () use ($data, $branchId, $user) {
            $po = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber($branchId),
                'branch_id' => $branchId,
                'supplier_id' => $data['supplier_id'] ?? null,
                'ordered_by' => $user->id,
                'order_date' => $data['order_date'],
                'expected_delivery' => $data['expected_delivery'] ?? null,
                'status' => PurchaseOrderStatusEnum::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $po->lines()->create([
                    'product_id' => $line['product_id'],
                    'ordered_qty' => round((float) $line['ordered_qty'], 2),
                    'received_qty' => 0,
                    'unit_cost' => $line['unit_cost'],
                    'subtotal' => round($line['ordered_qty'] * $line['unit_cost'], 2),
                ]);
            }

            return $po;
        });
    }

    private function generatePoNumber(int $branchId): string
    {
        $seq = PurchaseOrder::withTrashed()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('PO-%03d-%05d', $branchId, $seq);
    }
}
