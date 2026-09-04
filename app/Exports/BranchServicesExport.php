<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use App\Models\BranchService;
use App\Models\UserService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * خدمات فرعٍ واحد في ورقتين، تعودان بالاستيراد كما خرجتا.
 *
 * الفصل بين الورقتين ليس تجميلاً: الورقة الأولى صفٌّ لكل خدمة، والثانية صفٌّ لكل
 * (خدمة × موظف) — وحشوهما في ورقةٍ واحدة كان سيكرّر إعدادات الخدمة على كل موظف،
 * فيصير للخدمة الواحدة نسختان متعارضتان من «أقصى خصم» في الملف نفسه.
 *
 * ولا عمود «الفرع» هنا: الشاشة شاشةُ مدير فرعٍ يرى فرعه وحده، والاستيراد يهبط
 * على ذلك الفرع بعينه — فعمودٌ يقول غير ذلك وعدٌ لا يُوفى.
 */
class BranchServicesExport implements WithMultipleSheets
{
    public const SERVICES_SHEET = 'خدمات الفرع';

    public const COMMISSIONS_SHEET = 'عمولات الموظفين';

    /** فاصل أمثلة الملاحظات داخل الخلية الواحدة — يقرأه الاستيراد نفسه. */
    public const NOTE_SEPARATOR = ' | ';

    public function __construct(private readonly int $branchId) {}

    /** @return array<int, string> */
    public static function serviceHeadings(): array
    {
        return [
            'الخدمة',
            'نسبة العمولة',
            'أقصى خصم',
            'أعلى سعر',
            'أقل سعر',
            'نوع التسعير',
            'سعر المتر',
            'عمولة الوكيل للمتر',
            'تحضير',
            'لها خامات',
            'تكلفة الخامات',
            'أمثلة الملاحظات',
            'نشط',
        ];
    }

    /** @return array<int, string> */
    public static function commissionHeadings(): array
    {
        return ['الخدمة', 'الموظف', 'اسم المستخدم', 'نسبة العمولة'];
    }

    /** @return array<int, object> */
    public function sheets(): array
    {
        $services = $this->services();

        return [
            new ReportSheet(self::SERVICES_SHEET, self::serviceHeadings(), $this->serviceRows($services)),
            new ReportSheet(self::COMMISSIONS_SHEET, self::commissionHeadings(), $this->commissionRows($services)),
        ];
    }

    /** @return Collection<int, BranchService> */
    private function services(): Collection
    {
        return BranchService::query()
            ->where('branch_id', $this->branchId)
            ->with(['serviceTemplate', 'userCommissions.user'])
            ->get()
            ->sortBy(fn (BranchService $service) => $service->serviceTemplate?->name)
            ->values();
    }

    /**
     * @param  Collection<int, BranchService>  $services
     * @return Collection<int, array<int, mixed>>
     */
    private function serviceRows(Collection $services): Collection
    {
        return $services->map(fn (BranchService $service) => [
            $service->serviceTemplate?->name,
            $this->number($service->base_commission_pct),
            $this->number($service->max_discount_pct),
            // فارغٌ = مفتوح من تلك الجهة. صفرٌ هنا كان سيُقرأ سقفاً حقيقياً.
            $service->max_selling_price !== null ? $this->number($service->max_selling_price) : '',
            $service->min_selling_price !== null ? $this->number($service->min_selling_price) : '',
            $service->pricing_type?->label(),
            $this->number($service->price_per_sqm),
            $this->number($service->agent_commission_per_sqm),
            $service->is_tahazir ? 1 : 0,
            $service->has_materials ? 1 : 0,
            $this->number($service->materials_cost),
            implode(self::NOTE_SEPARATOR, $service->note_examples ?? []),
            $service->is_active ? 1 : 0,
        ]);
    }

    /**
     * @param  Collection<int, BranchService>  $services
     * @return Collection<int, array<int, mixed>>
     */
    private function commissionRows(Collection $services): Collection
    {
        return $services->flatMap(fn (BranchService $service) => $service->userCommissions
            ->filter(fn (UserService $rate) => $rate->user !== null)
            ->sortBy(fn (UserService $rate) => $rate->user->name)
            ->map(fn (UserService $rate) => [
                $service->serviceTemplate?->name,
                $rate->user->name,
                $rate->user->username,
                $this->number($rate->commission_override_pct),
            ])
            ->values());
    }

    /** رقمٌ بفاصلةٍ عشرية لاتينية بلا فواصل آلاف — يعود من Excel رقماً لا نصّاً. */
    private function number(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
