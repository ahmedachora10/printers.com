<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * تصدير تقرير المبيعات: ورقة الفواتير (حدثُ تحصيلٍ لكل صفّ) ثم ورقة «المبيعات
 * اليومية».
 *
 * الثانية أُضيفت مع تاسك 87: عمودا «المصروفات» و«الصافي» يُقرآن على الشاشة في
 * جدول اليوم لا في قائمة الفواتير، فبغير ورقةٍ تقابله يصدّر المستخدم ملفاً
 * ينقصه نصفُ ما رآه.
 */
class SalesReportExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  Collection<int, array<string, mixed>>  $invoices
     * @param  array<int, array<string, mixed>>  $byDay
     */
    public function __construct(
        private readonly Collection $invoices,
        private readonly array $byDay = [],
    ) {}

    /** @return array<int, ReportSheet> */
    public function sheets(): array
    {
        return [$this->invoicesSheet(), $this->dailySheet()];
    }

    private function invoicesSheet(): ReportSheet
    {
        return new ReportSheet(
            'الفواتير',
            // ⚠️ العناوين موضعية: أي عمودٍ يُضاف هنا يُضاف في map أدناه بالترتيب
            // نفسه، وإلا انزاح كل ما بعده عن عنوانه.
            ['رقم الفاتورة', 'النوع', 'الحركة', 'الفرع', 'الموظف', 'طريقة الدفع', 'الإجمالي قبل الخصم', 'الخصومات', 'الضريبة', 'التوصيل', 'الإجمالي', 'تاريخ الدفع'],
            $this->invoices->map(fn (array $inv) => [
                $inv['invoiceNumber'],
                $inv['type'],
                // «تحصيل» أو «مرتجع» — صفوف المرتجع تحمل أرقاماً سالبة.
                $inv['kind'],
                $inv['branchName'],
                $inv['userName'],
                $inv['methodName'],
                $this->money($inv['subtotal']),
                $this->money($inv['discounts']),
                $this->money($inv['vat']),
                $this->money($inv['shipping'] ?? 0),
                $this->money($inv['total']),
                $inv['paidAt'] ? Carbon::parse($inv['paidAt'])->format('d/m/Y') : '—',
            ]),
        );
    }

    private function dailySheet(): ReportSheet
    {
        return new ReportSheet(
            'المبيعات اليومية',
            ['التاريخ', 'عدد الفواتير', 'الإجمالي', 'المصروفات', 'الصافي'],
            collect($this->byDay)->map(fn (array $day) => [
                Carbon::parse($day['date'])->format('d/m/Y'),
                $day['count'],
                $this->money($day['total']),
                $this->money($day['expenses'] ?? 0),
                $this->money($day['net'] ?? 0),
            ]),
        );
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }
}
