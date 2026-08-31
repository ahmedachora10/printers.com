<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * تصدير تقرير الحوافز والخصومات: ثلاث أوراق — الملخّص لكل موظف، ثم تفصيل الخطط،
 * ثم تفصيل الخصومات.
 *
 * ثلاثٌ لا واحدة لأن الورقة الواحدة كانت ستُجبر الحسم والخطة على ترويسةٍ واحدة،
 * وهما شيئان مختلفان: للخطة مستهدَفٌ ونسبة إنجاز، وللحسم سببٌ ومن طبّقه.
 */
class IncentiveReportExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<int, array<string, mixed>>  $summary
     * @param  Collection<int, array<string, mixed>>  $plans
     * @param  Collection<int, array<string, mixed>>  $deductions
     */
    public function __construct(
        private readonly array $summary,
        private readonly Collection $plans,
        private readonly Collection $deductions,
        private readonly bool $withBranch,
    ) {
    }

    /** @return array<int, ReportSheet> */
    public function sheets(): array
    {
        return [
            $this->summarySheet(),
            $this->plansSheet(),
            $this->deductionsSheet(),
        ];
    }

    private function summarySheet(): ReportSheet
    {
        $headings = ['الموظف'];

        if ($this->withBranch) {
            $headings[] = 'الفرع';
        }

        return new ReportSheet(
            'ملخص الموظفين',
            [...$headings, 'عدد الخطط', 'المستهدف', 'المحقق', 'نسبة الإنجاز', 'المكافآت المستحقة', 'المكافآت المصروفة', 'الخصومات', 'الصافي'],
            collect($this->summary)->map(fn(array $row) => [
                $row['userName'] ?? '—',
                ...($this->withBranch ? [$row['branchName'] ?? '—'] : []),
                $row['planCount'],
                $this->money($row['target']),
                $this->money($row['achieved']),
                $row['progressPct'] . '%',
                $this->money($row['bonusEarned']),
                $this->money($row['bonusPaid']),
                $this->money($row['deductions']),
                $this->money($row['net']),
            ]),
        );
    }

    private function plansSheet(): ReportSheet
    {
        $headings = ['الموظف'];

        if ($this->withBranch) {
            $headings[] = 'الفرع';
        }

        return new ReportSheet(
            'خطط الحوافز',
            [...$headings, 'الفترة', 'المستهدف', 'المحقق', 'نسبة الإنجاز', 'المكافأة', 'المصروف', 'الحالة'],
            $this->plans->map(fn(array $row) => [
                $row['userName'] ?? '—',
                ...($this->withBranch ? [$row['branchName'] ?? '—'] : []),
                $row['periodLabel'],
                $this->money($row['target']),
                $this->money($row['achieved']),
                $row['progressPct'] . '%',
                $this->money($row['bonusAmount']),
                $this->money($row['bonusPaid']),
                $row['statusLabel'],
            ]),
        );
    }

    private function deductionsSheet(): ReportSheet
    {
        $headings = ['التاريخ', 'الموظف'];

        if ($this->withBranch) {
            $headings[] = 'الفرع';
        }

        return new ReportSheet(
            'الخصومات',
            [...$headings, 'القيمة', 'السبب', 'بواسطة', 'ملاحظات'],
            $this->deductions->map(fn(array $row) => [
                $row['deductedAt'] ? Carbon::parse($row['deductedAt'])->format('d/m/Y') : '—',
                $row['userName'] ?? '—',
                ...($this->withBranch ? [$row['branchName'] ?? '—'] : []),
                $this->money($row['amount']),
                $row['reasonText'],
                $row['deductedBy'] ?? '—',
                $row['notes'] ?? '—',
            ]),
        );
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }
}
