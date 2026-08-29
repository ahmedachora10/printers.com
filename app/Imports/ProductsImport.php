<?php

namespace App\Imports;

use App\Imports\Concerns\ReadsArabicHeadings;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Support\Import\ImportReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * تاسك 72: منتجات فرعٍ واحد من الورقة التي يكتبها ProductsExport.
 *
 * إضافة وتحديث فقط، والمفتاح `(sku, branch_id)` — وهو نفس المفتاح الفريد في
 * الجدول، فالسطر الموجود يُحدَّث ولا يُكرَّر، والجديد يُنشأ. لا حذف: منتجٌ اختفى
 * من الملف تبقى له حركات مخزون وسطور فواتير.
 *
 * ⚠️ ثلاثة فروق عن استيراد دليل الخدمات:
 *
 * 1. **`current_stock` لا يُكتب أبداً** — عمودٌ محسوب من `stock_movements`
 *    (قاعدة صريحة في CLAUDE.md). الملف قد يحمله (التصدير يكتبه للقراءة) فيُتجاهل
 *    **بصوتٍ عالٍ**: عدّادٌ في تقرير المعاينة يقول كم قيمةً تُجوهلت، لا صمتٌ يجعل
 *    المستخدم يظن أنه عدّل رصيده. ومن أراد رصيداً افتتاحياً كتبه بحركة
 *    `opening_stock` من شاشة الجرد.
 * 2. **الفئة والوحدة مرجعان لا نصّان** — يُطابقان بالاسم، والاسم المجهول يجعل
 *    السطر مرفوضاً بسببه؛ ولا تُنشأ فئةٌ صامتةً من ورقة منتجات.
 * 3. **الفرع يأتي من الطلب لا من الورقة** — عمود «الفرع» في التصدير للقراءة:
 *    الاستيراد يُنسب كل صفوفه إلى الفرع الذي اختاره المستخدم في نافذة الاستيراد،
 *    وهو ما تقوله له النافذة صراحةً.
 */
class ProductsImport implements ToCollection, WithHeadingRow
{
    use ReadsArabicHeadings;

    public const SKU = ['SKU', 'sku', 'رمز المنتج'];

    public const NAME = ['اسم المنتج', 'الاسم', 'name'];

    public const CATEGORY = ['الفئة', 'category'];

    public const UNIT = ['الوحدة', 'unit'];

    public const COST = ['سعر التكلفة', 'التكلفة', 'cost_price'];

    public const PRICE = ['سعر البيع', 'selling_price'];

    public const MIN_STOCK = ['الحد الأدنى', 'min_stock_level'];

    public const BARCODE = ['الباركود', 'barcode'];

    public const SQM = ['بالمتر المربع', 'is_sqm'];

    public const ACTIVE = ['نشط', 'active'];

    public const STOCK = ['المخزون الحالي', 'المخزون', 'current_stock'];

    public readonly ImportReport $report;

    /** @var array<string, int|null> */
    private array $categoryCache = [];

    /** @var array<string, int|null> */
    private array $unitCache = [];

    public function __construct(private readonly int $branchId, bool $dryRun = false)
    {
        $this->report = (new ImportReport($dryRun))
            ->declareCounter('productsCreated', 'منتجات جديدة', 'success')
            ->declareCounter('productsUpdated', 'منتجات محدَّثة', 'info')
            ->declareCounter('stockIgnored', 'قيم مخزون متجاهَلة', 'warning')
            ->declareCounter('skipped', 'صفوف متجاهَلة', 'warning');
    }

    /** @param  Collection<int, Collection<string, mixed>>  $rows */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $this->importRow($row, $index + 2);
        }
    }

    /** @param  Collection<string, mixed>  $row */
    private function importRow(Collection $row, int $number): void
    {
        if ($row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
            return; // صفٌّ فارغ في ذيل الورقة
        }

        $sku = $this->cell($row, self::SKU);
        $name = $this->cell($row, self::NAME);
        $label = $name ?? $sku ?? '—';

        if ($sku === null) {
            $this->report->skip($number, $label, 'الصف بلا SKU — وهو مفتاح مطابقة المنتج');

            return;
        }

        $product = Product::query()->firstOrNew(['sku' => $sku, 'branch_id' => $this->branchId]);
        $existed = $product->exists;

        if (! $existed && $name === null) {
            $this->report->skip($number, $sku, 'منتج جديد بلا اسم');

            return;
        }

        $categoryId = $this->resolveReference($row, self::CATEGORY, $this->categoryCache,
            fn (string $value) => ProductCategory::query()->where('name', $value)->value('id'));

        if ($categoryId === false) {
            $this->report->skip($number, $label, 'فئة غير معروفة: '.$this->cell($row, self::CATEGORY));

            return;
        }

        $unitId = $this->resolveReference($row, self::UNIT, $this->unitCache,
            fn (string $value) => ProductUnit::query()->where('name', $value)->value('id'));

        if ($unitId === false) {
            $this->report->skip($number, $label, 'وحدة غير معروفة: '.$this->cell($row, self::UNIT));

            return;
        }

        if (! $existed && ($categoryId === null || $unitId === null)) {
            $this->report->skip($number, $label, 'منتج جديد بلا فئة أو وحدة');

            return;
        }

        $cost = $this->money($row, self::COST);
        $price = $this->money($row, self::PRICE);
        $minStock = $this->money($row, self::MIN_STOCK);

        if ($cost === false || $price === false || $minStock === false) {
            $this->report->skip($number, $label, 'قيمة رقمية غير صالحة (سعر أو حد أدنى)');

            return;
        }

        if (! $existed && ($cost === null || $price === null)) {
            $this->report->skip($number, $label, 'منتج جديد بلا سعر تكلفة أو سعر بيع');

            return;
        }

        // عمود المخزون يُقرأ ليُبلَّغ عن تجاهله، لا ليُكتب: current_stock محسوب من
        // stock_movements وحدها.
        if ($this->cell($row, self::STOCK) !== null) {
            $this->report->count('stockIgnored');
        }

        $product->fill(array_filter([
            'name' => $name,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'cost_price' => $cost,
            'selling_price' => $price,
            'min_stock_level' => $minStock,
        ], fn ($value) => $value !== null));

        // الباركود يُمحى بعمودٍ أُفرغ عمداً، فلا يمرّ عبر array_filter أعلاه.
        if ($row->has(self::headingKey(self::BARCODE[0]))) {
            $product->barcode = $this->cell($row, self::BARCODE);
        }

        $product->is_sqm = $this->bool($row, self::SQM, $existed ? (bool) $product->is_sqm : false);
        $product->is_active = $this->bool($row, self::ACTIVE, $existed ? (bool) $product->is_active : true);
        $product->save();

        $this->report->count($existed ? 'productsUpdated' : 'productsCreated');
        $this->report->row($number, $label, $existed ? 'update' : 'create');
    }

    /**
     * معرّف مرجعٍ يُطابَق بالاسم: null حين لا عمود ولا قيمة، وfalse حين كُتب اسمٌ
     * لا يقابله شيء — وهو خطأ المستخدم يُبلَّغ به لا يُصحَّح صامتاً.
     *
     * @param  Collection<string, mixed>  $row
     * @param  array<int, string>  $labels
     * @param  array<string, int|null>  $cache
     * @param  callable(string): (int|null)  $lookup
     */
    private function resolveReference(Collection $row, array $labels, array &$cache, callable $lookup): int|false|null
    {
        $value = $this->cell($row, $labels);

        if ($value === null) {
            return null;
        }

        if (! array_key_exists($value, $cache)) {
            $cache[$value] = $lookup($value);
        }

        return $cache[$value] ?? false;
    }
}
