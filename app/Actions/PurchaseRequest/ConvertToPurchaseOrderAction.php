<?php

namespace App\Actions\PurchaseRequest;

use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Enums\PurchaseRequestStatusEnum;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns an approved internal request into a draft purchase order through the
 * existing M29 creation logic. No stock movement is written here — receiving
 * stays entirely in M29.
 */
class ConvertToPurchaseOrderAction
{
    public function __construct(private readonly CreatePurchaseOrderAction $createPurchaseOrder) {}

    /** @param array<string, mixed> $data */
    public function handle(PurchaseRequest $request, array $data = []): PurchaseOrder
    {
        if (! $request->status->canConvert()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن تحويل هذا الطلب إلى أمر شراء إلا بعد اعتماده، ولمرة واحدة فقط.',
            ]);
        }

        $request->load('lines.product');

        // A purchase order can only carry catalogued products; free-text items
        // have to be created in the inventory first.
        $lines = $request->lines->filter(fn (PurchaseRequestLine $line) => $line->product_id !== null);

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'لا يوجد في الطلب أصناف مُعرَّفة في المخزون؛ أضف الأصناف إلى المنتجات أولاً.',
            ]);
        }

        return DB::transaction(function () use ($request, $lines, $data) {
            $po = $this->createPurchaseOrder->handle([
                'branch_id' => $request->branch_id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'order_date' => $data['order_date'] ?? now()->format('Y-m-d'),
                'expected_delivery' => $data['expected_delivery'] ?? null,
                'notes' => $request->notes,
                'lines' => $lines->map(fn (PurchaseRequestLine $line) => [
                    'product_id' => $line->product_id,
                    'ordered_qty' => $line->qty,
                    'unit_cost' => (float) ($line->estimated_unit_cost ?? $line->product?->cost_price ?? 0),
                ])->values()->all(),
            ]);

            $request->update([
                'status' => PurchaseRequestStatusEnum::CONVERTED,
                'purchase_order_id' => $po->id,
            ]);

            return $po;
        });
    }
}
