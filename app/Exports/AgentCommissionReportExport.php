<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AgentCommissionReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(private readonly Collection $rows) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['المندوب', 'عدد الفواتير', 'إجمالي المبيعات', 'الخصم', 'الريبيت', 'عمولات البنود', 'الإجمالي المستحق', 'المدفوع', 'المتبقي'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => [
            $row['agentName'],
            $row['invoiceCount'],
            number_format((float) $row['sales'], 2),
            number_format((float) $row['discount'], 2),
            number_format((float) $row['rebate'], 2),
            number_format((float) $row['lineCommission'], 2),
            number_format((float) $row['due'], 2),
            number_format((float) $row['paid'], 2),
            number_format((float) $row['outstanding'], 2),
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
