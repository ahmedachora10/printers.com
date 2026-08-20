<?php

namespace App\Exports;

use App\Models\CatalogCategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Full catalogue export: one flat sheet, one row per price. The owning
 * category and subcategory are repeated on every row so the file round-trips
 * through CatalogueImport. Categories/subcategories with no prices still get
 * a row (with empty price columns) so the structure is preserved.
 *
 * تاسك 47 — the sheet holds **one owner's rows**: `$branchId = null` is the
 * shared catalogue, a branch id is that branch's own additions and price
 * overrides. Exporting only what the owner wrote is what makes the file safe
 * to re-import: dumping the effective view a branch *sees* would turn every
 * inherited row into a branch-owned duplicate on the way back in, quietly
 * cutting the branch off from later edits to the shared catalogue.
 */
class CatalogueExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(private readonly ?int $branchId = null) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['الفئة', 'الخدمة الفرعية', 'اسم البند', 'أقل سعر', 'أعلى سعر', 'السعر الأساسي', 'نشط'];
    }

    /** @return Collection<int, array<int, mixed>> */
    public function collection(): Collection
    {
        $rows = collect();

        // A subcategory belongs in the sheet when the owner wrote it, or when
        // it merely *holds* one of the owner's prices — a branch usually
        // re-prices inside the shared tree, and those price rows have to name
        // their (general) parents to find their way back in on import.
        $subcategoryInScope = fn ($q) => $q->where(fn ($q) => $this->ownedBy($q)
            ->orWhereHas('prices', fn ($q) => $this->ownedBy($q)));

        $categories = CatalogCategory::query()
            ->where(fn ($q) => $this->ownedBy($q)
                ->orWhereHas('subcategories', $subcategoryInScope))
            ->ordered()
            ->with([
                'subcategories' => fn ($q) => $subcategoryInScope($q)
                    ->ordered()
                    ->with(['prices' => fn ($q) => $this->ownedBy($q)->ordered()]),
            ])
            ->get();

        foreach ($categories as $category) {
            if ($category->subcategories->isEmpty()) {
                $rows->push([$category->name_ar, '', '', '', '', '', '']);

                continue;
            }

            foreach ($category->subcategories as $subcategory) {
                if ($subcategory->prices->isEmpty()) {
                    $rows->push([$category->name_ar, $subcategory->name_ar, '', '', '', '', '']);

                    continue;
                }

                foreach ($subcategory->prices as $price) {
                    $rows->push([
                        $category->name_ar,
                        $subcategory->name_ar,
                        $price->name,
                        number_format((float) $price->min_price, 2, '.', ''),
                        number_format((float) $price->max_price, 2, '.', ''),
                        number_format((float) $price->base_price, 2, '.', ''),
                        $price->is_active ? 1 : 0,
                    ]);
                }
            }
        }

        return $rows;
    }

    /**
     * Rows this sheet's owner wrote — and only those.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return \Illuminate\Database\Eloquent\Builder<*>
     */
    private function ownedBy($query)
    {
        return $this->branchId === null
            ? $query->whereNull('branch_id')
            : $query->where('branch_id', $this->branchId);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
