<?php

namespace App\Imports;

use App\Imports\Concerns\ReadsArabicHeadings;
use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use App\Support\Import\ImportReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Full catalogue import from the flat sheet produced by CatalogueExport.
 * Upsert-only: categories (by name), subcategories (by name within category)
 * and prices (by name within subcategory) are created if missing and updated
 * if present. Nothing is ever deleted. The "نشط" / active column applies to
 * the price row only.
 *
 * تاسك 47 — everything the sheet creates lands in **one owner's scope**:
 * `$branchId = null` writes the shared catalogue, a branch id writes that
 * branch's own rows. A name that already exists is reused rather than
 * duplicated, and the branch's own row wins over a general one of the same
 * name — so a branch importing a price under a shared category attaches to
 * that shared category instead of forking a private copy of it.
 *
 * The run always fills an ImportReport. A dry run is the caller's job, not
 * this class's: it runs the very same import inside a transaction it rolls
 * back, which is why the preview's numbers are the commit's numbers.
 */
class CatalogueImport implements ToCollection, WithHeadingRow
{
    use ReadsArabicHeadings;

    public const CATEGORY = ['الفئة', 'category'];

    public const SUBCATEGORY = ['الخدمة الفرعية', 'subcategory'];

    public const PRICE_NAME = ['اسم البند', 'البند', 'price_name'];

    public const MIN = ['أقل سعر', 'اقل سعر', 'min'];

    public const MAX = ['أعلى سعر', 'اعلى سعر', 'max'];

    public const BASE = ['السعر الأساسي', 'السعر الاساسي', 'base'];

    public const ACTIVE = ['نشط', 'active'];

    public readonly ImportReport $report;

    /** @var array<string, CatalogCategory> */
    private array $categoryCache = [];

    /** @var array<string, CatalogSubcategory> */
    private array $subcategoryCache = [];

    public function __construct(private readonly ?int $branchId = null, bool $dryRun = false)
    {
        $this->report = (new ImportReport($dryRun))
            ->declareCounter('categoriesCreated', 'فئات جديدة', 'success')
            ->declareCounter('subcategoriesCreated', 'خدمات فرعية جديدة', 'success')
            ->declareCounter('pricesCreated', 'بنود أسعار جديدة', 'success')
            ->declareCounter('pricesUpdated', 'بنود أسعار محدَّثة', 'info')
            ->declareCounter('skipped', 'صفوف متجاهَلة', 'warning');
    }

    /**
     * Maatwebsite already wraps the whole import in a DB transaction
     * (config/excel.php → transactions.handler = db), so no extra wrapper here.
     *
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // +2: the heading row, and Excel counting from 1. The number in the
            // report has to be the number the user sees in the sheet.
            $this->importRow($row, $index + 2);
        }
    }

    /** @param  Collection<string, mixed>  $row */
    private function importRow(Collection $row, int $number): void
    {
        if ($row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
            return; // trailing blank row — not something to report
        }

        $categoryName = $this->cell($row, self::CATEGORY);

        if ($categoryName === null) {
            $this->report->skip($number, '—', 'الصف بلا فئة');

            return;
        }

        $category = $this->resolveCategory($categoryName);

        $subName = $this->cell($row, self::SUBCATEGORY);

        if ($subName === null) {
            // A category with no services yet: the export writes such a row to
            // preserve the structure, so the import has to accept it back.
            $this->report->row($number, $categoryName, 'ok');

            return;
        }

        $subcategory = $this->resolveSubcategory($category, $subName);
        $path = $categoryName.' › '.$subName;

        $priceName = $this->cell($row, self::PRICE_NAME);

        if ($priceName === null) {
            $this->report->row($number, $path, 'ok');

            return;
        }

        $path .= ' › '.$priceName;

        $min = $this->money($row, self::MIN);
        $max = $this->money($row, self::MAX);
        $base = $this->money($row, self::BASE);

        if ($min === false || $max === false || $base === false) {
            $this->report->skip($number, $path, 'قيمة سعر غير رقمية');

            return;
        }

        $min ??= 0.0;
        $base ??= 0.0;

        $price = CatalogPrice::firstOrNew([
            'subcategory_id' => $subcategory->id,
            'branch_id' => $this->branchId,
            'name' => $priceName,
        ]);

        $existed = $price->exists;

        $price->fill([
            'min_price' => $min,
            'max_price' => max($max ?? 0.0, $min),
            'base_price' => $base,
            'is_active' => $this->bool($row, self::ACTIVE),
        ])->save();

        $this->report->count($existed ? 'pricesUpdated' : 'pricesCreated');
        $this->report->row($number, $path, $existed ? 'update' : 'create');
    }

    /**
     * Reuse a category this owner can already see — its own first, then the
     * shared one — and only create when neither exists. Creating blindly would
     * fork a private copy of a shared category on every branch import.
     */
    private function resolveCategory(string $name): CatalogCategory
    {
        return $this->categoryCache[$name] ??= CatalogCategory::query()
            ->where('name_ar', $name)
            ->forBranch($this->branchId)
            ->orderByRaw('branch_id is null')
            ->first()
            ?? tap(
                CatalogCategory::create(['name_ar' => $name, 'branch_id' => $this->branchId]),
                fn () => $this->report->count('categoriesCreated'),
            );
    }

    private function resolveSubcategory(CatalogCategory $category, string $name): CatalogSubcategory
    {
        $key = $category->id.'|'.$name;

        return $this->subcategoryCache[$key] ??= CatalogSubcategory::query()
            ->where('category_id', $category->id)
            ->where('name_ar', $name)
            ->forBranch($this->branchId)
            ->orderByRaw('branch_id is null')
            ->first()
            ?? tap(
                CatalogSubcategory::create([
                    'category_id' => $category->id,
                    'name_ar' => $name,
                    'branch_id' => $this->branchId,
                ]),
                fn () => $this->report->count('subcategoriesCreated'),
            );
    }
}
