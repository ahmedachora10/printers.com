<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\StockMovementTypeEnum;
use App\Models\BranchServiceMaterial;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;

/**
 * تاسك 50 (وشقّ المخزون من تاسك 54): يخصم خامات الخدمة من المخزون ويعيدها.
 *
 * الخصم يقع عند **اعتماد** الفاتورة لا عند إنشائها آجلة — نفس لحظة العمولة
 * ونقاط الولاء، فالفاتورة التي لم تُعتمد بعد لم تُنفَّذ ولم تُستهلك خاماتها.
 * والإرجاع يكتب حركة `return_in` مقابلة لكل حركة صرف — بلا حذف أي حركة، فسجلّ
 * المخزون جدول إدراج فقط.
 *
 * وحدة الاستهلاك هي **الكمية المحاسَب عليها في السطر**: عدد القطع لخدمة بالقطعة،
 * ومساحة السطر بالمتر المربع لخدمة مسعّرة بالمتر — فمترٌ من الفينيل لكل متر مبيع
 * من البنر. ويُقرأ ذلك من لقطة السطر نفسها (وجود المقاس عليه) لا من تعريف الخدمة
 * اليوم، فتغييرُ التسعير لاحقاً لا يعيد كتابة ما بيع.
 *
 * تكلفة الخامات المحاسبية (`materials_cost`, تاسك 7) تبقى كما هي ولا تتضاعف:
 * هذه حركاتُ مخزونٍ لا قيود مالية، والاثنتان مستقلّتان — قد تُعرَّف إحداهما دون
 * الأخرى على الخدمة نفسها.
 */
class ConsumeServiceMaterialsAction
{
    public function __construct(
        private readonly RecordStockMovementAction $recordStockMovement,
    ) {}

    /**
     * Draw each line's materials out of stock. Call inside the approval's
     * transaction; a no-op for an invoice whose services define no materials.
     *
     * Stock is allowed to go negative here — refusing to approve an invoice for
     * work already delivered would strand it in the review queue, and a negative
     * balance is exactly the signal that the register needs a count.
     */
    public function consume(ServiceInvoice $invoice, int $actorId): void
    {
        // Never twice for the same invoice: approval is already gated on status,
        // this only guards a re-entrant call path.
        if ($this->movementsFor($invoice, StockMovementTypeEnum::SALE_OUT)->exists()) {
            return;
        }

        $invoice->loadMissing('lines.branchService.materials.product');

        foreach ($invoice->lines as $line) {
            /** @var ServiceInvoiceLine $line */
            $materials = $line->branchService?->materials ?? collect();
            $billableQty = $line->billableQty();

            foreach ($materials as $material) {
                /** @var BranchServiceMaterial $material */
                $qty = $material->consumptionFor($billableQty);

                // A material whose product was deleted since is skipped rather
                // than blocking the approval — the invoice is the record of the
                // sale, not of the catalogue.
                if ($qty <= 0 || $material->product === null) {
                    continue;
                }

                $this->recordStockMovement->handle(
                    $material->product,
                    StockMovementTypeEnum::SALE_OUT,
                    $qty,
                    [
                        'unit_cost' => $material->product->cost_price,
                        'reference_id' => $invoice->id,
                        'reference_type' => ServiceInvoice::class,
                        // للتقرير وحده: المرجع يبقى الفاتورة، وهذا يقول أي خدمةٍ منها استهلكت.
                        'service_invoice_line_id' => $line->id,
                        'created_by' => $actorId,
                        'notes' => "خامات الفاتورة {$invoice->invoice_number} — {$line->service_name}",
                    ],
                );
            }
        }
    }

    /**
     * Put back exactly what the invoice drew — read off the movements it wrote,
     * not recomputed from the service definition, so a service whose materials
     * changed in between still balances to zero.
     */
    public function restore(ServiceInvoice $invoice, int $actorId): void
    {
        if ($this->movementsFor($invoice, StockMovementTypeEnum::RETURN_IN)->exists()) {
            return;
        }

        $consumed = $this->movementsFor($invoice, StockMovementTypeEnum::SALE_OUT)
            ->with('product')
            ->get();

        foreach ($consumed as $movement) {
            /** @var StockMovement $movement */
            if ($movement->product === null) {
                continue;
            }

            $this->recordStockMovement->handle(
                $movement->product,
                StockMovementTypeEnum::RETURN_IN,
                abs((float) $movement->qty),
                [
                    'unit_cost' => $movement->unit_cost,
                    'reference_id' => $invoice->id,
                    'reference_type' => ServiceInvoice::class,
                    'service_invoice_line_id' => $movement->service_invoice_line_id,
                    'created_by' => $actorId,
                    'notes' => "إرجاع خامات الفاتورة {$invoice->invoice_number}",
                ],
            );
        }
    }

    /**
     * The invoice's own material movements of one direction.
     *
     * @return Builder<StockMovement>
     */
    private function movementsFor(ServiceInvoice $invoice, StockMovementTypeEnum $type): Builder
    {
        return StockMovement::query()
            ->where('reference_type', ServiceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->where('type', $type);
    }
}
