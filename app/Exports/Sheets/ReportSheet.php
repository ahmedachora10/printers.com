<?php

namespace App\Exports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ورقةٌ واحدة داخل ملفٍ متعدّد الأوراق: عنوانٌ وترويسةٌ وصفوف، لا أكثر.
 *
 * صُنعت عامّة لأن كل ورقةٍ في تقاريرنا هي هذا بعينه؛ صنفٌ مستقلٌّ لكل ورقة كان
 * سيكرّر الترويسة العريضة و`ShouldAutoSize` في كل مرّة بلا فارق.
 */
class ReportSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<int, string>  $headings
     * @param  Collection<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly Collection $rows,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return $this->headings;
    }

    /** @return Collection<int, array<int, mixed>> */
    public function collection(): Collection
    {
        return $this->rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
