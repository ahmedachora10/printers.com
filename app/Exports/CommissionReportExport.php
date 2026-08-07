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
        return ['الموظف', 'رقم الفاتورة', 'الخدمة', 'النوع', 'الشريحة', 'المصدر', 'المبلغ', 'للعمولات', 'تكلفة الخامات', 'الحالة', 'تاريخ الاستحقاق', 'تاريخ الصرف'];
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
            number_format((float) $line['lineCommission'], 2),
            number_format((float) ($line['materials'] ?? 0), 2),
            $this->invoiceStatusLabel((string) $line['invoiceStatus']),
            $line['earnedAt'] ? Carbon::parse($line['earnedAt'])->format('d/m/Y') : '—',
            $line['paidAt'] ? Carbon::parse($line['paidAt'])->format('d/m/Y') : '—',
        ]);
    }

    /**
     * Map a service-invoice status to its Arabic label as shown in the report's
     * "الحالة" column (invoice approval state, not commission-disbursement state).
     */
    private function invoiceStatusLabel(string $status): string
    {
        return match ($status) {
            'cancelled' => 'ملغاة',
            'due' => 'غير مسددة',
            'returned' => 'مرتجعة',
            default => 'معتمدة',
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
