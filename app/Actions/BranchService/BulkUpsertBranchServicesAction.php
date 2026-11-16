<?php

namespace App\Actions\BranchService;

use App\Models\BranchService;
use App\Models\ServiceTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * ربط/تعديل قالبٍ على عدّة فروع دفعةً واحدة. الفرع المربوط تُكتب فيه أعمدة
 * المجموعات المختارة وحدها ويبقى غيرها على قيمه؛ وغير المربوط يُربط بالقيم
 * كاملة (المجموعات غير المختارة تصل منسوخة من فرع المصدر في الواجهة).
 */
class BulkUpsertBranchServicesAction
{
    /** مجموعات تُطبَّق معاً كي لا يُنتج الدمجُ أرضيةً فوق السقف أو تسعيراً بلا سعر. */
    public const GROUPS = [
        'base_commission_pct' => ['base_commission_pct'],
        'max_discount_pct' => ['max_discount_pct'],
        'pricing' => ['pricing_type', 'price_per_sqm', 'agent_commission_per_sqm'],
        'price_bounds' => ['max_selling_price', 'min_selling_price'],
        'note_examples' => ['note_examples'],
        'materials' => ['has_materials', 'materials_cost'],
        'is_active' => ['is_active'],
    ];

    public function __construct(
        private readonly AttachBranchServiceAction $attach,
        private readonly UpdateBranchServiceAction $update,
    ) {}

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<int, string>  $groups
     * @param  array<string, mixed>  $values
     */
    public function handle(ServiceTemplate $template, array $branchIds, array $groups, array $values): void
    {
        $columns = array_merge(...array_values(Arr::only(self::GROUPS, $groups)) ?: [[]]);

        DB::transaction(function () use ($template, $branchIds, $columns, $values): void {
            $existing = BranchService::where('service_template_id', $template->id)
                ->whereIn('branch_id', $branchIds)
                ->get()
                ->keyBy('branch_id');

            foreach ($branchIds as $branchId) {
                if ($service = $existing->get($branchId)) {
                    if ($columns !== []) {
                        $this->update->handle($service, Arr::only($values, $columns));
                    }

                    continue;
                }

                $this->attach->handle([...$values, 'service_template_id' => $template->id, 'branch_id' => $branchId]);
            }
        });
    }
}
