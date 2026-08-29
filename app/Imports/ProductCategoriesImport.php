<?php

namespace App\Imports;

use App\Imports\Concerns\ReadsArabicHeadings;
use App\Models\ProductCategory;
use App\Support\Import\ImportReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * تاسك 72: فئات المنتجات من الورقة التي يكتبها ProductCategoriesExport.
 *
 * إضافة وتحديث فقط — لا حذف: فئةٌ اختفت من الملف تبقى في النظام، إذ قد تكون
 * معلّقةً بمنتجات. والمطابقة بالاسم لأنه المفتاح الفريد الوحيد في الجدول
 * (`unique:product_categories,name` في نموذج الإنشاء).
 *
 * العناوين تُقرأ عبر ReadsArabicHeadings — راجع ذلك التريت لسبب أن
 * `$row['الاسم']` لا يطابق شيئاً أبداً.
 */
class ProductCategoriesImport implements ToCollection, WithHeadingRow
{
    use ReadsArabicHeadings;

    public const NAME = ['الاسم', 'اسم الفئة', 'الفئة', 'name'];

    public const ACTIVE = ['نشط', 'الحالة', 'active'];

    public readonly ImportReport $report;

    public function __construct(bool $dryRun = false)
    {
        $this->report = (new ImportReport($dryRun))
            ->declareCounter('categoriesCreated', 'فئات جديدة', 'success')
            ->declareCounter('categoriesUpdated', 'فئات محدَّثة', 'info')
            ->declareCounter('skipped', 'صفوف متجاهَلة', 'warning');
    }

    /** @param  Collection<int, Collection<string, mixed>>  $rows */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // +2: صفّ العناوين، وعدّ Excel من 1 — الرقم في التقرير هو الرقم الذي
            // يراه المستخدم في ورقته.
            $this->importRow($row, $index + 2);
        }
    }

    /** @param  Collection<string, mixed>  $row */
    private function importRow(Collection $row, int $number): void
    {
        if ($row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
            return; // صفٌّ فارغ في ذيل الورقة — لا شيء يُبلَّغ عنه
        }

        $name = $this->cell($row, self::NAME);

        if ($name === null) {
            $this->report->skip($number, '—', 'الصف بلا اسم فئة');

            return;
        }

        $category = ProductCategory::query()->firstOrNew(['name' => $name]);
        $existed = $category->exists;

        $category->fill([
            'is_active' => $this->bool($row, self::ACTIVE, $existed ? (bool) $category->is_active : true),
        ])->save();

        $this->report->count($existed ? 'categoriesUpdated' : 'categoriesCreated');
        $this->report->row($number, $name, $existed ? 'update' : 'create');
    }
}
