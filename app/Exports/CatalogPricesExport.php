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
    /**
     * One branch's price sheet, or the general list when $branchId is null
     * (تاسك 47) — the sheet round-trips through CatalogPricesImport into the
     * very same scope it was exported from.
     */
    public function __construct(
        private readonly int $subcategoryId,
        private readonly ?int $branchId = null,
    ) {}

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
            ->when($this->branchId === null,
                fn ($q) => $q->whereNull('branch_id'),
                fn ($q) => $q->where('branch_id', $this->branchId))
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
