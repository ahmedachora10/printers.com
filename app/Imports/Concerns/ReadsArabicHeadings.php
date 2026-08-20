<?php

namespace App\Imports\Concerns;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

/**
 * Reads a cell by its **human** heading, whatever the heading formatter did
 * to it.
 *
 * `WithHeadingRow` does not key rows by the heading you see in the sheet: it
 * slugs it, and Str::slug transliterates Arabic to latin — «الفئة» arrives as
 * `alfy`, «اسم البند» as `asm_albnd`. Looking those keys up by the Arabic text
 * silently found nothing, so every row of an exported catalogue was skipped
 * and the import reported success having written not one row.
 *
 * Formatting the accepted labels through the very same formatter is what keeps
 * an export and its re-import from drifting apart again: change a heading in
 * the export and the lookup follows it, because both start from the same
 * string.
 */
trait ReadsArabicHeadings
{
    /** @var array<string, string> */
    private static array $headingKeyCache = [];

    /**
     * The first non-empty cell among the accepted headings for one column.
     *
     * @param  Collection<string, mixed>  $row
     * @param  array<int, string>  $labels
     */
    protected function cell(Collection $row, array $labels): ?string
    {
        foreach ($labels as $label) {
            $value = $row->get(self::headingKey($label));

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    protected static function headingKey(string $label): string
    {
        return self::$headingKeyCache[$label] ??= (string) HeadingRowFormatter::format([$label])[0];
    }

    /**
     * A money cell: null when the column is absent or blank, false when it
     * holds something that is not a number (the row is the user's to fix).
     */
    protected function money(Collection $row, array $labels): float|false|null
    {
        $raw = $this->cell($row, $labels);

        if ($raw === null) {
            return null;
        }

        // Sheets exported from Excel carry thousands separators and Arabic-Indic
        // digits; neither makes the number invalid.
        $normalized = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '٫', ',', ' ', ' '],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '', '', ''],
            $raw,
        );

        return is_numeric($normalized) ? (float) $normalized : false;
    }

    protected function bool(Collection $row, array $labels, bool $default = true): bool
    {
        $raw = $this->cell($row, $labels);

        if ($raw === null) {
            return $default;
        }

        return ! in_array(mb_strtolower($raw), ['0', 'false', 'no', 'لا', 'غير نشط', 'معطل'], true);
    }
}
