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

        // تاسك 68: approval already wrote the purchase_in movements. Receiving
        // a purchase order on top would double the quantity in an insert-only
        // ledger, so the two paths are mutually exclusive.
        if ($request->stock_fed_at !== null) {
            throw ValidationException::withMessages([
                'status' => 'غُذّي المخزون بهذا الطلب عند اعتماده، فلا يُحوَّل إلى أمر شراء حتى لا تُحتسب الكمية مرّتين.',
            ]);
        }

        $request->load('lines.product', 'lines.approvedProduct');

        // A purchase order can only carry catalogued products; free-text items
        // have to be created in the inventory first. تاسك 89: المنتج المعتمد
        // يسبق المقترح — القرار هو ما يُشترى.
        $lines = $request->lines->filter(fn (PurchaseRequestLine $line) => $line->effectiveProductId() !== null);

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
                    'product_id' => $line->effectiveProductId(),
                    'ordered_qty' => $line->effectiveQty(),
                    'unit_cost' => $line->effectiveUnitCost()
                        ?? (float) ($line->approvedProduct?->cost_price ?? $line->product?->cost_price ?? 0),
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
