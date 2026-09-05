<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /**
     * @param  Collection<int, array<string, mixed>>  $invoices
     */
    public function __construct(private readonly Collection $invoices) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['رقم الفاتورة', 'النوع', 'الحركة', 'الفرع', 'الموظف', 'طريقة الدفع', 'الإجمالي قبل الخصم', 'الخصومات', 'الضريبة', 'الإجمالي', 'تاريخ الدفع'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return $this->invoices->map(fn (array $inv) => [
            $inv['invoiceNumber'],
            $inv['type'],
            // «تحصيل» أو «مرتجع» — صفوف المرتجع تحمل أرقاماً سالبة.
            $inv['kind'],
            $inv['branchName'],
            $inv['userName'],
            $inv['methodName'],
            number_format((float) $inv['subtotal'], 2),
            number_format((float) $inv['discounts'], 2),
            number_format((float) $inv['vat'], 2),
            number_format((float) $inv['total'], 2),
            $inv['paidAt'] ? Carbon::parse($inv['paidAt'])->format('d/m/Y') : '—',
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
