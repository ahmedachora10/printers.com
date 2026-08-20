<?php

namespace App\Imports;

use App\Models\CatalogPrice;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CatalogPricesImport implements ToCollection, WithHeadingRow
{
    public function __construct(
        private readonly int $subcategoryId,
        private readonly ?int $branchId = null,
    ) {}

    /**
     * Upsert rows on (subcategory_id, branch_id, name) — a null branch writes
     * the general prices, a branch id writes that branch's own list (تاسك 47).
     *
     * Expected headings (Arabic or English): الاسم/name, أقل_سعر/min_price,
     * أعلى_سعر/max_price, السعر_الأساسي/base_price.
     *
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? $row['الاسم'] ?? ''));

            if ($name === '') {
                continue;
            }

            $min = (float) ($row['min_price'] ?? $row['اقل_سعر'] ?? $row['أقل_سعر'] ?? 0);
            $max = (float) ($row['max_price'] ?? $row['اعلى_سعر'] ?? $row['أعلى_سعر'] ?? 0);
            $base = (float) ($row['base_price'] ?? $row['السعر_الاساسي'] ?? $row['السعر_الأساسي'] ?? 0);

            CatalogPrice::updateOrCreate(
                ['subcategory_id' => $this->subcategoryId, 'branch_id' => $this->branchId, 'name' => $name],
                [
                    'min_price' => $min,
                    'max_price' => max($max, $min),
                    'base_price' => $base,
                ],
            );
        }
    }
}
