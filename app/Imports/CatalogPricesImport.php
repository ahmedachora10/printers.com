<?php

namespace App\Imports;

use App\Imports\Concerns\ReadsArabicHeadings;
use App\Models\CatalogPrice;
use App\Support\Import\ImportReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * One subcategory's price list, from the sheet CatalogPricesExport writes.
 *
 * Headings are matched through ReadsArabicHeadings — see that trait for why
 * `$row['الاسم']` never matched anything.
 */
class CatalogPricesImport implements ToCollection, WithHeadingRow
{
    use ReadsArabicHeadings;

    public const NAME = ['الاسم', 'اسم البند', 'name'];

    public const MIN = ['أقل سعر', 'اقل سعر', 'min_price'];

    public const MAX = ['أعلى سعر', 'اعلى سعر', 'max_price'];

    public const BASE = ['السعر الأساسي', 'السعر الاساسي', 'base_price'];

    public const ACTIVE = ['نشط', 'active'];

    public readonly ImportReport $report;

    public function __construct(
        private readonly int $subcategoryId,
        private readonly ?int $branchId = null,
        bool $dryRun = false,
    ) {
        $this->report = (new ImportReport($dryRun))
            ->declareCounter('pricesCreated', 'بنود أسعار جديدة', 'success')
            ->declareCounter('pricesUpdated', 'بنود أسعار محدَّثة', 'info')
            ->declareCounter('skipped', 'صفوف متجاهَلة', 'warning');
    }

    /**
     * Upsert rows on (subcategory_id, branch_id, name) — a null branch writes
     * the general prices, a branch id writes that branch's own list (تاسك 47).
     *
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $number = $index + 2;

            if ($row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
                continue;
            }

            $name = $this->cell($row, self::NAME);

            if ($name === null) {
                $this->report->skip($number, '—', 'الصف بلا اسم بند');

                continue;
            }

            $min = $this->money($row, self::MIN);
            $max = $this->money($row, self::MAX);
            $base = $this->money($row, self::BASE);

            if ($min === false || $max === false || $base === false) {
                $this->report->skip($number, $name, 'قيمة سعر غير رقمية');

                continue;
            }

            $min ??= 0.0;
            $base ??= 0.0;

            $price = CatalogPrice::firstOrNew([
                'subcategory_id' => $this->subcategoryId,
                'branch_id' => $this->branchId,
                'name' => $name,
            ]);

            $existed = $price->exists;

            $price->fill([
                'min_price' => $min,
                'max_price' => max($max ?? 0.0, $min),
                'base_price' => $base,
                'is_active' => $this->bool($row, self::ACTIVE, $existed ? (bool) $price->is_active : true),
            ])->save();

            $this->report->count($existed ? 'pricesUpdated' : 'pricesCreated');
            $this->report->row($number, $name, $existed ? 'update' : 'create');
        }
    }
}
