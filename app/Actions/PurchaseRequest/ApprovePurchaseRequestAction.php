<?php

namespace App\Actions\PurchaseRequest;

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\PurchaseRequestStatusEnum;
use App\Enums\StockMovementTypeEnum;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تاسك 68: approving an internal request now feeds the branch's stock in the
 * same breath — one `purchase_in` movement per line, at the unit cost the
 * approver settled on.
 *
 * ⚠️ `stock_movements` is insert-only: a duplicated movement can never be
 * corrected, only offset by an adjustment entry. Two guards keep each line to
 * exactly one movement — `canDecide()` refuses a second decision, and
 * `stock_fed_at` closes the old convert-to-purchase-order path afterwards, so
 * the same quantity can never arrive twice.
 */
class ApprovePurchaseRequestAction
{
    public function __construct(private readonly RecordStockMovementAction $recordStockMovement) {}

    /**
     * @param  array<int, array{product_id: int, unit_cost: float}>  $linesById
     *                                                                           keyed by purchase_request_lines.id — what the approver settled
     *                                                                           on for each line (its inventory product and its unit cost).
     */
    public function handle(PurchaseRequest $request, array $linesById): PurchaseRequest
    {
        if (! $request->status->canDecide()) {
            throw ValidationException::withMessages([
                'status' => 'تم اتخاذ قرار في هذا الطلب مسبقاً.',
            ]);
        }

        $request->load('lines');

        // Every line has to be settled: a line left out would be approved
        // without ever reaching the inventory.
        $unsettled = $request->lines->reject(fn (PurchaseRequestLine $line) => isset($linesById[$line->id]));

        if ($unsettled->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'اربط كل صنف بمنتج في المخزون واكتب تكلفته قبل الاعتماد.',
            ]);
        }

        return DB::transaction(function () use ($request, $linesById) {
            foreach ($request->lines as $line) {
                $settled = $linesById[$line->id];
                $product = Product::findOrFail($settled['product_id']);

                $line->update([
                    'product_id' => $product->id,
                    // A line the requester typed by hand takes the name and the
                    // unit of the product it was just linked to.
                    'item_name' => $product->name,
                    'is_sqm' => (bool) $product->is_sqm,
                    'estimated_unit_cost' => $settled['unit_cost'],
                ]);

                $this->recordStockMovement->handle(
                    $product,
                    StockMovementTypeEnum::PURCHASE_IN,
                    (float) $line->qty,
                    [
                        'unit_cost' => $settled['unit_cost'],
                        'reference_id' => $request->id,
                        'reference_type' => PurchaseRequest::class,
                        'notes' => "اعتماد طلب الشراء الداخلي رقم {$request->id}",
                    ],
                );
            }

            $request->update([
                'status' => PurchaseRequestStatusEnum::APPROVED,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'decision_reason' => null,
                // Sealed: a fed request never walks the purchase-order path too.
                'stock_fed_at' => now(),
            ]);

            return $request;
        });
    }
}
