<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** تاسك 146 — تصدير تقرير مبالغ الشبكة؛ نفس صفوف الشاشة. */
class NetworkReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(private readonly Collection $rows) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['التاريخ', 'الفرع', 'الجهاز', 'رقم الجهاز', 'نوع البطاقة', 'المبلغ', 'الحالة'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => [
            Carbon::parse($row['date'])->format('d/m/Y'),
            $row['branchName'],
            $row['deviceName'],
            $row['deviceNumber'],
            $row['cardTypeName'],
            number_format((float) $row['amount'], 2),
            $row['approved'] ? 'معتمدة' : 'غير معتمدة',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
