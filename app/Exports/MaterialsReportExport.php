<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MaterialsReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /** @param Collection<int, array<string, mixed>> $movements */
    public function __construct(private readonly Collection $movements) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['التاريخ', 'الحركة', 'الخامة', 'الوحدة', 'الكمية', 'المصدر', 'الفاتورة', 'الفرع', 'تكلفة الوحدة', 'التكلفة', 'المستخدم'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return $this->movements->map(fn (array $row) => [
            Carbon::parse($row['date'])->format('d/m/Y'),
            $row['directionLabel'],
            $row['productName'],
            $row['unitName'] ?? '—',
            number_format((float) $row['qty'], 2),
            $row['serviceName'] ?? '—',
            $row['invoiceNumber'] ?? '—',
            $row['branchName'] ?? '—',
            number_format((float) $row['unitCost'], 2),
            number_format((float) $row['cost'], 2),
            $row['userName'] ?? '—',
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
