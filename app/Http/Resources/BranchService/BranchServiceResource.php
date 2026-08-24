<?php

namespace App\Http\Resources\BranchService;

use App\Models\BranchService;
use App\Models\BranchServiceMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Handles both a BranchService model and a pivot-laden row from the
 * branch <-> serviceTemplate belongsToMany relation.
 *
 * @mixin BranchService
 *
 * @property BranchService|null $pivot
 * @property string|null $name
 */
class BranchServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $hasPivot = $this->pivot !== null;

        return [
            'id' => $hasPivot ? $this->pivot->id : $this->id,
            'branchId' => $hasPivot ? $this->id : $this->branch_id,
            'branchName' => $hasPivot ? $this->name : $this->branch?->name,
            'serviceTemplateId' => $hasPivot ? $this->pivot->service_template_id : $this->service_template_id,
            'serviceTemplateName' => $hasPivot ? null : $this->serviceTemplate?->name,
            'baseCommissionPct' => (float) ($hasPivot ? $this->pivot->base_commission_pct : $this->base_commission_pct),
            'maxDiscountPct' => (float) ($hasPivot ? $this->pivot->max_discount_pct : $this->max_discount_pct),
            // حدّا سعر البيع — null يعني «مفتوح» من تلك الجهة، فلا يُصبّ أحدهما
            // في float وإلا صار صفراً وقرأته نقطة البيع حدّاً حقيقياً.
            'maxSellingPrice' => ($hasPivot ? $this->pivot->max_selling_price : $this->max_selling_price) !== null
                ? (float) ($hasPivot ? $this->pivot->max_selling_price : $this->max_selling_price)
                : null,
            'minSellingPrice' => ($hasPivot ? $this->pivot->min_selling_price : $this->min_selling_price) !== null
                ? (float) ($hasPivot ? $this->pivot->min_selling_price : $this->min_selling_price)
                : null,
            'pricingType' => ($hasPivot ? $this->pivot->pricing_type : $this->pricing_type)?->value ?? 'unit',
            'pricePerSqm' => (float) ($hasPivot ? $this->pivot->price_per_sqm : $this->price_per_sqm),
            'agentCommissionPerSqm' => (float) ($hasPivot ? $this->pivot->agent_commission_per_sqm : $this->agent_commission_per_sqm),
            'noteExamples' => array_values(($hasPivot ? $this->pivot->note_examples : $this->note_examples) ?? []),
            'isTahazir' => (bool) ($hasPivot ? $this->pivot->is_tahazir : $this->is_tahazir),
            'hasMaterials' => (bool) ($hasPivot ? $this->pivot->has_materials : $this->has_materials),
            'materialsCost' => (float) ($hasPivot ? $this->pivot->materials_cost : $this->materials_cost),
            // خامات المخزون (تاسك 50) — مستقلّة تماماً عن materialsCost أعلاه:
            // تلك رقم يُخصم من عمولة الموظف، وهذه حركات تُخصم من المخزون.
            'materials' => $hasPivot ? [] : $this->materials
                ->map(fn (BranchServiceMaterial $m) => [
                    'productId' => $m->product_id,
                    'productName' => $m->product?->name,
                    'unitName' => $m->product?->is_sqm ? 'متر مربع' : $m->product?->unit?->name,
                    'qtyPerUnit' => (float) $m->qty_per_unit,
                    'wastePct' => (float) $m->waste_pct,
                ])
                ->values()
                ->all(),
            'isActive' => (bool) ($hasPivot ? $this->pivot->is_active : $this->is_active),
            'createdAt' => ($hasPivot ? $this->pivot->created_at : $this->created_at)?->toISOString(),
            'updatedAt' => ($hasPivot ? $this->pivot->updated_at : $this->updated_at)?->toISOString(),
        ];
    }
}
