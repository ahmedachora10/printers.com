<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CommissionReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    public function __construct(private readonly Collection $lines) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['الموظف', 'رقم الفاتورة', 'الخدمة', 'النوع', 'الشريحة', 'المصدر', 'المبلغ', 'الحالة', 'تاريخ الاستحقاق', 'تاريخ الصرف'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return $this->lines->map(fn (array $line) => [
            $line['userName'],
            $line['invoiceNumber'],
            $line['serviceName'],
            $line['isTahazir'] ? 'تحضير' : 'عادي',
            $line['tierApplied'] !== null ? (string) $line['tierApplied'] : '—',
            $line['sourceLabel'],
            number_format((float) $line['amount'], 2),
            $line['paidAt'] ? 'مصروفة' : 'معلقة',
            $line['earnedAt'] ? Carbon::parse($line['earnedAt'])->format('d/m/Y') : '—',
            $line['paidAt'] ? Carbon::parse($line['paidAt'])->format('d/m/Y') : '—',
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
