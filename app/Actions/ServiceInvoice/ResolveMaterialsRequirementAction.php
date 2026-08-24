<?php

namespace App\Actions\ServiceInvoice;

use App\Models\BranchService;
use App\Models\BranchServiceMaterial;
use App\Models\Product;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Support\MaterialsRequirement;

/**
 * يحسب ما تحتاجه أسطرُ خدماتٍ من خامات المخزون ويقارنه بالمتاح.
 *
 * الصيغة ليست هنا: الكميةُ المحاسَب عليها يقرأها السطرُ من نفسه
 * (`ServiceInvoiceLine::billableQty`)، وكميةَ الخامة يحسبها صفُّ الخامة
 * (`BranchServiceMaterial::consumptionFor`) بهالكها — فما يُعرض هنا هو بعينه ما
 * يخصمه ConsumeServiceMaterialsAction عند الاعتماد، لا حسابٌ موازٍ يوشك أن يتباعد.
 *
 * المتاح هو `products.current_stock` — عمودٌ حقيقي يحدّثه hook حركةِ المخزون بعد
 * كل كتابة، فلا حاجة إلى SUM فرعي على السجلّ.
 */
class ResolveMaterialsRequirementAction
{
    /**
     * احتياج فاتورة قائمة — يُستدعى قبل الاعتماد وعند بناء حوار التأكيد.
     */
    public function forInvoice(ServiceInvoice $invoice): MaterialsRequirement
    {
        $invoice->loadMissing('lines.branchService.materials.product.unit');

        $demand = [];

        foreach ($invoice->lines as $line) {
            /** @var ServiceInvoiceLine $line */
            $billableQty = $line->billableQty();

            foreach ($line->branchService?->materials ?? [] as $material) {
                /** @var BranchServiceMaterial $material */
                $this->add($demand, $material, $billableQty);
            }
        }

        return $this->build($demand);
    }

    /**
     * احتياج سلّةٍ لم تُحفظ بعد: أسطرٌ خامٌ من نقطة البيع.
     *
     * @param  list<array{branch_service_id: int, billable_qty: float}>  $lines
     */
    public function forLines(array $lines): MaterialsRequirement
    {
        $serviceIds = array_values(array_unique(array_column($lines, 'branch_service_id')));

        if ($serviceIds === []) {
            return MaterialsRequirement::empty();
        }

        $services = BranchService::query()
            ->whereIn('id', $serviceIds)
            ->with('materials.product.unit')
            ->get()
            ->keyBy('id');

        $demand = [];

        foreach ($lines as $line) {
            $service = $services->get($line['branch_service_id']);

            foreach ($service?->materials ?? [] as $material) {
                /** @var BranchServiceMaterial $material */
                $this->add($demand, $material, (float) $line['billable_qty']);
            }
        }

        return $this->build($demand);
    }

    /**
     * يضمّ احتياج خامةٍ واحدة إلى مجموع منتجها. الخامةُ التي حُذف منتجها تُتجاهل
     * هنا كما يتجاهلها الخصم — الفاتورة سجلُّ بيعٍ لا سجلُّ كتالوج.
     *
     * @param  array<int, array{product: Product, required: float}>  $demand
     */
    private function add(array &$demand, BranchServiceMaterial $material, float $billableQty): void
    {
        $qty = $material->consumptionFor($billableQty);

        if ($qty <= 0 || $material->product === null) {
            return;
        }

        $productId = $material->product->id;

        $demand[$productId] ??= ['product' => $material->product, 'required' => 0.0];
        $demand[$productId]['required'] += $qty;
    }

    /** @param array<int, array{product: Product, required: float}> $demand */
    private function build(array $demand): MaterialsRequirement
    {
        $rows = collect($demand)
            ->map(function (array $row): array {
                $required = round($row['required'], 2);
                $available = round((float) $row['product']->current_stock, 2);

                return [
                    'productId' => $row['product']->id,
                    'name' => $row['product']->name,
                    'unitName' => $row['product']->unit?->name,
                    'required' => $required,
                    'available' => $available,
                    'shortage' => round(max(0, $required - $available), 2),
                ];
            })
            ->sortByDesc('shortage')
            ->values();

        return new MaterialsRequirement($rows);
    }
}
