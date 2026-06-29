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
 */
class CatalogueExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /** @return array<int, string> */
    public function headings(): array
    {
        return ['الفئة', 'الخدمة الفرعية', 'اسم البند', 'أقل سعر', 'أعلى سعر', 'السعر الأساسي', 'نشط'];
    }

    /** @return Collection<int, array<int, mixed>> */
    public function collection(): Collection
    {
        $rows = collect();

        $categories = CatalogCategory::query()
            ->ordered()
            ->with([
                'subcategories' => fn ($q) => $q->ordered()->with(['prices' => fn ($q) => $q->ordered()]),
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

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
