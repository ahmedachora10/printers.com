<?php

namespace App\Imports;

use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Full catalogue import from the flat sheet produced by CatalogueExport.
 * Upsert-only: categories (by name), subcategories (by name within category)
 * and prices (by name within subcategory) are created if missing and updated
 * if present. Nothing is ever deleted. The "نشط" / active column applies to
 * the price row only.
 */
class CatalogueImport implements ToCollection, WithHeadingRow
{
    /** @var array<string, CatalogCategory> */
    private array $categoryCache = [];

    /** @var array<string, CatalogSubcategory> */
    private array $subcategoryCache = [];

    /**
     * Maatwebsite already wraps the whole import in a DB transaction
     * (config/excel.php → transactions.handler = db), so no extra wrapper here.
     *
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $categoryName = trim((string) ($row['category'] ?? $row['الفئة'] ?? ''));
            if ($categoryName === '') {
                continue;
            }

            $category = $this->resolveCategory($categoryName);

            $subName = trim((string) ($row['subcategory'] ?? $row['الخدمة_الفرعية'] ?? ''));
            if ($subName === '') {
                continue;
            }

            $subcategory = $this->resolveSubcategory($category, $subName);

            $priceName = trim((string) ($row['price_name'] ?? $row['اسم_البند'] ?? $row['البند'] ?? ''));
            if ($priceName === '') {
                continue;
            }

            $min = (float) ($row['min'] ?? $row['اقل_سعر'] ?? $row['أقل_سعر'] ?? 0);
            $max = (float) ($row['max'] ?? $row['اعلى_سعر'] ?? $row['أعلى_سعر'] ?? 0);
            $base = (float) ($row['base'] ?? $row['السعر_الاساسي'] ?? $row['السعر_الأساسي'] ?? 0);
            $activeRaw = $row['active'] ?? $row['نشط'] ?? 1;

            CatalogPrice::updateOrCreate(
                ['subcategory_id' => $subcategory->id, 'name' => $priceName],
                [
                    'min_price' => $min,
                    'max_price' => max($max, $min),
                    'base_price' => $base,
                    'is_active' => $this->toBool($activeRaw),
                ],
            );
        }
    }

    private function resolveCategory(string $name): CatalogCategory
    {
        return $this->categoryCache[$name] ??= CatalogCategory::firstOrCreate(['name_ar' => $name]);
    }

    private function resolveSubcategory(CatalogCategory $category, string $name): CatalogSubcategory
    {
        $key = $category->id.'|'.$name;

        return $this->subcategoryCache[$key] ??= CatalogSubcategory::firstOrCreate([
            'category_id' => $category->id,
            'name_ar' => $name,
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return ! in_array($normalized, ['0', '', 'false', 'no', 'لا', 'غير نشط'], true);
    }
}
