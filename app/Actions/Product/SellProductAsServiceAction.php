<?php

namespace App\Actions\Product;

use App\Actions\BranchService\AttachBranchServiceAction;
use App\Actions\BranchService\SyncBranchServiceMaterialsAction;
use App\Actions\UserService\SeedUserServiceCommissionsAction;
use App\Enums\ServicePricingTypeEnum;
use App\Models\BranchService;
use App\Models\Product;
use App\Models\ServiceTemplate;
use App\Models\UserService;
use Illuminate\Support\Facades\DB;

/**
 * «بيع كخدمة»: المنتج يُباع من شاشة الموظف كسطر خدمةٍ عادي، فيأخذ كل ما في
 * الخدمات — الاعتماد والعمولة والمساعدين والمندوبين والشحن والإرجاع — بالكود نفسه.
 *
 * تُنشأ له مرةً واحدة خدمةُ فرعٍ مربوطة به (`branch_services.product_id`) بقالبٍ
 * خاصٍّ بفرعه، وخامتُها المنتج نفسه بكمية 1 — فمنها يُسحب المخزون. ثم تتبع
 * المنتج عند كل حفظ ({@see sync()}): الاسم وسعر البيع والتكلفة والحالة.
 *
 * التكلفة تُكتب «تكلفة الخامات» فتُطرح من أساس العمولة: العمولة من الربح. ومنتجٌ
 * تكلفته صفر يصير خدمةً مفتوحة التكلفة يكتبها الموظف وقت البيع (تاسك 77).
 */
class SellProductAsServiceAction
{
    public function __construct(
        private readonly AttachBranchServiceAction $attach,
        private readonly SyncBranchServiceMaterialsAction $syncMaterials,
        private readonly SeedUserServiceCommissionsAction $seedCommissions,
    ) {}

    public function handle(Product $product, float $commissionPct): BranchService
    {
        return DB::transaction(function () use ($product, $commissionPct): BranchService {
            $existing = $product->branchService;

            if ($existing !== null && $existing->product_sale_stopped_at === null) {
                return $existing;
            }

            // إعادة تحويلٍ بعد إيقاف: الخدمة نفسها تعود — لا قالبٌ مكرّر — والنسبة
            // المُدخلة تسري على الموظفين جميعاً كما في التحويل الأول.
            if ($existing !== null) {
                $existing->update([
                    'product_sale_stopped_at' => null,
                    'base_commission_pct' => $commissionPct,
                    ...$this->mirrored($product),
                ]);
                UserService::where('branch_service_id', $existing->id)->update(['commission_override_pct' => $commissionPct]);
                $this->seedCommissions->handle($existing, $commissionPct);

                return $existing;
            }

            $template = ServiceTemplate::create([
                'branch_id' => $product->branch_id,
                'name' => $product->name,
                'is_active' => true,
            ]);

            $branchService = $this->attach->handle([
                'service_template_id' => $template->id,
                'branch_id' => $product->branch_id,
                'product_id' => $product->id,
                'base_commission_pct' => $commissionPct,
                'max_discount_pct' => 0,
                // صفوف عمولة الموظفين بالنسبة المُدخلة، لا بعمولة كلٍّ منهم الأساسية.
                'seed_commission_pct' => $commissionPct,
                ...$this->mirrored($product),
            ]);

            $this->syncMaterials->handle($branchService, [
                ['product_id' => $product->id, 'qty_per_unit' => 1, 'waste_pct' => 0],
            ]);

            return $branchService;
        });
    }

    /**
     * «إيقاف البيع كخدمة»: يعود المنتج منتجاً فقط. الخدمة تُعطَّل ولا تُحذف —
     * الفواتير المفتوحة عليها تكمل طبيعياً ويُسحب مخزونها عند الدفع.
     */
    public function stop(Product $product): void
    {
        $product->branchService?->update(['product_sale_stopped_at' => now(), 'is_active' => false]);
    }

    /** يُستدعى من حفظ المنتج وحذفه (Product::booted). */
    public function sync(Product $product): void
    {
        $branchService = $product->branchService()->with('serviceTemplate')->first();

        // الموقوفة لا تتبع حالة المنتج، وإلا أعادها تفعيلُه إلى شاشة الموظف.
        if ($branchService === null || $branchService->product_sale_stopped_at !== null) {
            return;
        }

        DB::transaction(function () use ($product, $branchService): void {
            $branchService->serviceTemplate?->update(['name' => $product->name]);
            $branchService->update($this->mirrored($product));
        });
    }

    /** @return array<string, mixed> ما تأخذه الخدمة من المنتج */
    private function mirrored(Product $product): array
    {
        return [
            'pricing_type' => $product->is_sqm ? ServicePricingTypeEnum::Sqm->value : ServicePricingTypeEnum::Unit->value,
            // «سعر وحدة القياس»: سعر القطعة أو سعر المتر، يملأ السطر في نقطة البيع.
            'price_per_sqm' => $product->selling_price,
            // تاسك 241: شرائح الكمية بالشكل نفسه — يبيع الموظف بشرائح المنتج وحدودها.
            'price_tiers' => $product->price_tiers,
            'has_materials' => true,
            'materials_cost' => $product->cost_price,
            'is_active' => $product->is_active && ! $product->trashed(),
        ];
    }
}
