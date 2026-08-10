<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExpenseReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /**
     * @param  Collection<int, array<string, mixed>>  $expenses
     */
    public function __construct(private readonly Collection $expenses) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['التاريخ', 'الفئة', 'الفرع', 'المورّد', 'الكمية', 'سعر الوحدة', 'الإجمالي', 'المرجع', 'مَن سجّلها'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return $this->expenses->map(fn (array $expense) => [
            Carbon::parse($expense['date'])->format('d/m/Y'),
            $expense['categoryName'],
            $expense['branchName'] ?? '—',
            $expense['supplierName'] ?? '—',
            number_format((float) $expense['qty'], 2),
            number_format((float) $expense['unitPrice'], 2),
            number_format((float) $expense['total'], 2),
            $expense['receiptReference'] ?? '—',
            $expense['userName'] ?? '—',
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
