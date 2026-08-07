<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DailyReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly bool $showPurchases,
        private readonly bool $detailed = false,
    ) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        $headings = ['التاريخ'];

        if ($this->detailed) {
            $headings[] = 'الموظف';
        }

        array_push($headings, 'المنتجات', 'الخدمات', 'الإجمالي', 'المحصَّل', 'عمولة الموظفين');

        if ($this->showPurchases) {
            $headings[] = 'المشتريات';
            $headings[] = 'المبلغ المتبقي';
        }

        $headings[] = 'الضريبة';

        return $headings;
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return $this->rows->map(function (array $row) {
            $cells = [Carbon::parse($row['date'])->format('d/m/Y')];

            if ($this->detailed) {
                $cells[] = $row['isTotal'] ? 'الإجمالي' : (string) $row['employeeName'];
            }

            array_push(
                $cells,
                number_format((float) $row['products'], 2),
                number_format((float) $row['services'], 2),
                number_format((float) $row['total'], 2),
                number_format((float) $row['collected'], 2),
                number_format((float) $row['commission'], 2),
            );

            if ($this->showPurchases) {
                $cells[] = number_format((float) $row['purchases'], 2);
                $cells[] = number_format((float) $row['remaining'], 2);
            }

            $cells[] = number_format((float) $row['vat'], 2);

            return $cells;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
