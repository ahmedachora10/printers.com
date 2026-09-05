<?php

namespace App\Actions\ServiceTemplate;

use App\Actions\UserService\SeedUserServiceCommissionsAction;
use App\Models\BranchService;
use App\Models\ServiceTemplate;
use App\Models\UserService;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 83: نسخةٌ كاملة من القالب — بفروعه وشروط كلٍّ منها وعمولات موظفيه.
 *
 * الحاجة من اللقطة نفسها: ثمانية أسطر «استاند رول آب» لا تختلف إلا في المقاس
 * داخل الاسم، وكلّها مربوطة بثلاثة فروع. فالمُعفى منه إعادةُ الربط وشروطه، لا
 * كتابةُ الاسم — ولذلك تبدأ النسخة **غير نشطة** ويُطالَب المستخدم بتسميتها.
 */
class DuplicateServiceTemplateAction
{
    /** اللاحقة التي تميّز النسخة، ويُلحق بها رقمٌ عند تكرار التكرار. */
    private const SUFFIX = '— نسخة';

    public function handle(ServiceTemplate $template): ServiceTemplate
    {
        return DB::transaction(function () use ($template): ServiceTemplate {
            $copy = $template->replicate(['created_at', 'updated_at']);
            $copy->name = $this->availableName($template->name);
            // غير نشطة عمداً: نسخةٌ اسمها «… — نسخة» لا تظهر في نقطة البيع.
            $copy->is_active = false;
            // ترتيبُ الأصل نفسه، فتقع النسخة بجواره لا في ذيل القائمة (تاسك 82).
            $copy->save();

            foreach ($template->branches()->get() as $branch) {
                /** @var BranchService $source */
                $source = $branch->pivot;

                $copy->branches()->attach($branch->id, [
                    'base_commission_pct' => $source->base_commission_pct,
                    'max_discount_pct' => $source->max_discount_pct,
                    'max_selling_price' => $source->max_selling_price,
                    'min_selling_price' => $source->min_selling_price,
                    'pricing_type' => $source->pricing_type?->value,
                    'price_per_sqm' => $source->price_per_sqm,
                    'agent_commission_per_sqm' => $source->agent_commission_per_sqm,
                    'note_examples' => $source->note_examples ?? [],
                    'is_tahazir' => $source->is_tahazir,
                    'has_materials' => $source->has_materials,
                    'materials_cost' => $source->materials_cost,
                    'is_active' => $source->is_active,
                ]);

                $new = BranchService::query()
                    ->where('service_template_id', $copy->id)
                    ->where('branch_id', $branch->id)
                    ->firstOrFail();

                $this->copyEmployeeRates($source->id, $new->id);
            }

            return $copy;
        });
    }

    /**
     * عمولات الموظفين تُنسخ كما هي: النسخة توأمُ الأصل وموظفوها موظفوه.
     *
     * ⚠️ ولا تُستدعى هنا {@see SeedUserServiceCommissionsAction}
     * (تاسك 85): تلك تكتب العمولة الأساسية لكل موظفي الفرع عند **خدمةٍ جديدة**،
     * وهنا موظفٌ بلا صفٍّ في الأصل استُثني عمداً — فيبقى مستثنى في التوأم بدل
     * أن يكسب نسبةً لم يكسبها على الأصل.
     */
    private function copyEmployeeRates(int $sourceBranchServiceId, int $newBranchServiceId): void
    {
        $rows = UserService::query()
            ->where('branch_service_id', $sourceBranchServiceId)
            ->get(['user_id', 'commission_override_pct'])
            ->map(fn (UserService $rate) => [
                'user_id' => $rate->user_id,
                'branch_service_id' => $newBranchServiceId,
                'commission_override_pct' => $rate->commission_override_pct,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            UserService::query()->insert($rows);
        }
    }

    /**
     * «الاسم — نسخة»، فإن كان محجوزاً فـ«— نسخة 2» وهكذا. لا قيد تفرّد في
     * القاعدة، لكن اسمين متطابقين في شاشةٍ واحدة عطبٌ بحدّ ذاته.
     */
    private function availableName(string $name): string
    {
        $base = trim($name).' '.self::SUFFIX;
        $taken = ServiceTemplate::query()
            ->where('name', 'like', $base.'%')
            ->pluck('name')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $index = 2;

        while (in_array($base.' '.$index, $taken, true)) {
            $index++;
        }

        return $base.' '.$index;
    }
}
