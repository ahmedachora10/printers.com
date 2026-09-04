<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * An empty sheet with the headings one import expects, plus a filled-in
 * example row. The headings are handed in by the matching export class rather
 * than retyped here — a template that disagrees with the export is a trap.
 */
class ImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $sampleRows
     * @param  string|null  $title  named only by a template of several sheets,
     *                              where the sheet name is what tells the two apart
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $sampleRows = [],
        private readonly ?string $title = null,
    ) {}

    public function title(): string
    {
        return $this->title ?? 'النموذج';
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return $this->headings;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return $this->sampleRows;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            // The example row is greyed out: it is there to be overwritten.
            2 => ['font' => ['italic' => true, 'color' => ['argb' => 'FF9CA3AF']]],
        ];
    }
}
