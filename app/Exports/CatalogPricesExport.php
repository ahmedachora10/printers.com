<?php

namespace App\Exports;

use App\Models\CatalogPrice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CatalogPricesExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(private readonly int $subcategoryId) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['الاسم', 'أقل سعر', 'أعلى سعر', 'السعر الأساسي'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return CatalogPrice::query()
            ->where('subcategory_id', $this->subcategoryId)
            ->ordered()
            ->get()
            ->map(fn (CatalogPrice $price) => [
                $price->name,
                number_format((float) $price->min_price, 2, '.', ''),
                number_format((float) $price->max_price, 2, '.', ''),
                number_format((float) $price->base_price, 2, '.', ''),
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
