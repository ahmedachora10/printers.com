<?php

namespace App\Imports\Sheets;

use App\Actions\BranchService\AttachBranchServiceAction;
use App\Actions\BranchService\UpdateBranchServiceAction;
use App\Enums\ServicePricingTypeEnum;
use App\Imports\Concerns\ReadsArabicHeadings;
use App\Models\BranchService;
use App\Models\ServiceTemplate;
use App\Support\Import\ImportReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * الورقة الأولى: صفٌّ لكل خدمة من خدمات الفرع.
 *
 * المطابقة باسم الخدمة، وهو المفتاح الوحيد الذي يملكه المستخدم في ورقته
 * (المعرّفات لا تُصدَّر عمداً — ملفٌ يُنقل بين بيئتين تصير معرّفاته كذباً). واسمٌ
 * لا يقابله قالبٌ متاحٌ للفرع يُنشئ قالباً **مملوكاً للفرع** (تاسك 45): فبناء
 * قائمة خدمات فرعٍ جديد من ملفٍ واحد هو نصف الغاية من هذه الشاشة، والبديل —
 * تجاهل الصف — كان سيطالب المستخدم بإنشاء كل خدمة يدوياً قبل رفع ملفه.
 *
 * وعمودٌ غائبٌ عن الورقة لا يُمسّ أصلاً: من قصّ ملفه إلى عمودَي «الخدمة» و«نسبة
 * العمولة» يعدّل العمولات وحدها، ولا يجرف بقية الإعدادات إلى أصفارها.
 */
class BranchServicesSheetImport implements ToCollection, WithHeadingRow
{
    use ReadsArabicHeadings;

    public const SERVICE = ['الخدمة', 'اسم الخدمة', 'service'];

    public const COMMISSION = ['نسبة العمولة', 'العمولة', 'base_commission_pct'];

    public const MAX_DISCOUNT = ['أقصى خصم', 'max_discount_pct'];

    public const MAX_PRICE = ['أعلى سعر', 'أعلى سعر للبيع', 'max_selling_price'];

    public const MIN_PRICE = ['أقل سعر', 'أقل سعر للبيع', 'min_selling_price'];

    public const PRICING_TYPE = ['نوع التسعير', 'pricing_type'];

    public const PRICE_PER_SQM = ['سعر المتر', 'سعر المتر المربع', 'price_per_sqm'];

    public const AGENT_PER_SQM = ['عمولة الوكيل للمتر', 'agent_commission_per_sqm'];

    public const TAHAZIR = ['تحضير', 'is_tahazir'];

    public const HAS_MATERIALS = ['لها خامات', 'has_materials'];

    public const MATERIALS_COST = ['تكلفة الخامات', 'materials_cost'];

    public const NOTES = ['أمثلة الملاحظات', 'note_examples'];

    public const ACTIVE = ['نشط', 'الحالة', 'active'];

    /** ما يُقبل نصّاً لنوع التسعير، إلى جانب قيمتَي الـenum نفسيهما. */
    private const PRICING_LABELS = [
        'بالوحدة' => 'unit',
        'وحدة' => 'unit',
        'بالمتر المربع' => 'sqm',
        'المتر المربع' => 'sqm',
        'متر مربع' => 'sqm',
    ];

    public function __construct(
        private readonly int $branchId,
        private readonly ImportReport $report,
    ) {}

    /** @param  Collection<int, Collection<string, mixed>>  $rows */
    public function collection(Collection $rows): void
    {
        $attach = app(AttachBranchServiceAction::class);
        $update = app(UpdateBranchServiceAction::class);

        foreach ($rows as $index => $row) {
            // +2: صفّ العناوين، وعدّ Excel من 1 — الرقم في التقرير هو الرقم الذي
            // يراه المستخدم في ورقته.
            $this->importRow($row, $index + 2, $attach, $update);
        }
    }

    /** @param  Collection<string, mixed>  $row */
    private function importRow(
        Collection $row,
        int $number,
        AttachBranchServiceAction $attach,
        UpdateBranchServiceAction $update,
    ): void {
        if ($row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
            return; // صفٌّ فارغ في ذيل الورقة — لا شيء يُبلَّغ عنه
        }

        $name = $this->cell($row, self::SERVICE);

        if ($name === null) {
            $this->report->skip($number, '—', 'الصف بلا اسم خدمة');

            return;
        }

        $numbers = [
            'base_commission_pct' => $this->money($row, self::COMMISSION),
            'max_discount_pct' => $this->money($row, self::MAX_DISCOUNT),
            'max_selling_price' => $this->money($row, self::MAX_PRICE),
            'min_selling_price' => $this->money($row, self::MIN_PRICE),
            'price_per_sqm' => $this->money($row, self::PRICE_PER_SQM),
            'agent_commission_per_sqm' => $this->money($row, self::AGENT_PER_SQM),
            'materials_cost' => $this->money($row, self::MATERIALS_COST),
        ];

        if (in_array(false, $numbers, true)) {
            $this->report->skip($number, $name, 'قيمة غير رقمية في أحد أعمدة النسب أو الأسعار');

            return;
        }

        if (collect($numbers)->contains(fn (?float $value) => $value !== null && $value < 0)) {
            $this->report->skip($number, $name, 'قيمة سالبة — النسب والأسعار لا تقلّ عن صفر');

            return;
        }

        foreach (['base_commission_pct' => 'نسبة العمولة', 'max_discount_pct' => 'أقصى خصم'] as $key => $label) {
            if ($numbers[$key] !== null && $numbers[$key] > 100) {
                $this->report->skip($number, $name, $label.' يجب أن تكون بين 0 و100');

                return;
            }
        }

        $pricingType = $this->pricingType($row);

        if ($pricingType === false) {
            $this->report->skip($number, $name, 'نوع تسعير غير معروف: '.$this->cell($row, self::PRICING_TYPE));

            return;
        }

        $notes = $this->noteExamples($row);

        if ($notes === false) {
            $this->report->skip($number, $name, 'أمثلة الملاحظات: 10 كحدٍّ أقصى، وكلٌّ منها 120 حرفاً فأقل');

            return;
        }

        $template = ServiceTemplate::query()
            ->availableToBranch($this->branchId)
            ->where('name', $name)
            ->first();

        $service = $template === null ? null : BranchService::query()
            ->where('branch_id', $this->branchId)
            ->where('service_template_id', $template->id)
            ->first();

        // الأسعار الحدّية وحدها تحتاج التفريق بين «عمودٍ غائب» و«خليّةٍ أُفرغت
        // عمداً»: الأولى تُبقي الحدّ، والثانية تفتحه. وبقية الأعمدة يكفيها أن
        // تسقط على القيمة القائمة.
        $maxPrice = $this->hasColumn($row, self::MAX_PRICE)
            ? $numbers['max_selling_price']
            : $this->floatOrNull($service?->max_selling_price);

        $minPrice = $this->hasColumn($row, self::MIN_PRICE)
            ? $numbers['min_selling_price']
            : $this->floatOrNull($service?->min_selling_price);

        if ($maxPrice !== null && $maxPrice > 0 && $minPrice !== null && $minPrice > $maxPrice) {
            $this->report->skip($number, $name, 'أقل سعر للبيع لا يجوز أن يتجاوز أعلى سعر');

            return;
        }

        $data = [
            'base_commission_pct' => $this->effective($numbers['base_commission_pct'], $service?->base_commission_pct),
            'max_discount_pct' => $this->effective($numbers['max_discount_pct'], $service?->max_discount_pct),
            'max_selling_price' => $maxPrice,
            'min_selling_price' => $minPrice,
            'pricing_type' => $pricingType ?? $service?->pricing_type?->value ?? ServicePricingTypeEnum::Unit->value,
            'price_per_sqm' => $this->effective($numbers['price_per_sqm'], $service?->price_per_sqm),
            'agent_commission_per_sqm' => $this->effective($numbers['agent_commission_per_sqm'], $service?->agent_commission_per_sqm),
            'materials_cost' => $this->effective($numbers['materials_cost'], $service?->materials_cost),
            'note_examples' => $notes ?? $service?->note_examples ?? [],
            'is_tahazir' => $this->bool($row, self::TAHAZIR, (bool) $service?->is_tahazir),
            'has_materials' => $this->bool($row, self::HAS_MATERIALS, (bool) $service?->has_materials),
            'is_active' => $this->bool($row, self::ACTIVE, $service === null ? true : (bool) $service->is_active),
        ];

        if ($data['pricing_type'] === ServicePricingTypeEnum::Sqm->value && $data['price_per_sqm'] <= 0) {
            $this->report->skip($number, $name, 'أدخل سعر المتر المربع للخدمات المسعّرة بالمتر المربع');

            return;
        }

        if ($service !== null) {
            $update->handle($service, $data);
            $this->report->count('servicesUpdated');
            $this->report->row($number, $name, 'update');

            return;
        }

        if ($template === null) {
            // تاسك 45: خدمةٌ مملوكة لهذا الفرع، لا خدمة عامة تظهر لكل الفروع.
            $template = ServiceTemplate::query()->create([
                'name' => $name,
                'branch_id' => $this->branchId,
                'is_active' => true,
            ]);

            $this->report->count('templatesCreated');
        }

        $attach->handle([
            ...$data,
            'branch_id' => $this->branchId,
            'service_template_id' => $template->id,
        ]);

        $this->report->count('servicesCreated');
        $this->report->row($number, $name, 'create');
    }

    /**
     * نوع التسعير المكتوب في الورقة: null حين لا قيمة (فيبقى ما كان)، وfalse حين
     * كُتب نصٌّ لا يقابل نوعاً — وهو خطأ يُبلَّغ به لا يُصحَّح صامتاً.
     *
     * @param  Collection<string, mixed>  $row
     */
    private function pricingType(Collection $row): string|false|null
    {
        $raw = $this->cell($row, self::PRICING_TYPE);

        if ($raw === null) {
            return null;
        }

        return self::PRICING_LABELS[$raw]
            ?? ServicePricingTypeEnum::tryFrom(mb_strtolower($raw))?->value
            ?? false;
    }

    /**
     * أمثلة الملاحظات من خليّةٍ واحدة يفصل بينها «|» أو سطرٌ جديد: null حين لا
     * عمود، وfalse حين خالفت حدود الشاشة نفسها (10 أمثلة، 120 حرفاً).
     *
     * @param  Collection<string, mixed>  $row
     * @return array<int, string>|false|null
     */
    private function noteExamples(Collection $row): array|false|null
    {
        if (! $this->hasColumn($row, self::NOTES)) {
            return null;
        }

        $examples = collect(preg_split('/[|\r\n]+/u', (string) $this->cell($row, self::NOTES)) ?: [])
            ->map(fn (string $example) => trim($example))
            ->filter(fn (string $example) => $example !== '')
            ->unique()
            ->values();

        if ($examples->count() > 10 || $examples->contains(fn (string $example) => mb_strlen($example) > 120)) {
            return false;
        }

        return $examples->all();
    }

    /** قيمة الورقة، فالقيمة القائمة، فالصفر — بهذا الترتيب. */
    private function effective(?float $fromSheet, mixed $current): float
    {
        return $fromSheet ?? (float) ($current ?? 0);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
