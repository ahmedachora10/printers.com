<?php

namespace App\Exports;

use App\Models\ProductCategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * تاسك 72: فئات المنتجات كاملةً في ورقةٍ واحدة، تعود بالاستيراد كما خرجت.
 *
 * تاسك 237: الورقة ما يراه المستخدم — كل الفئات للسوبر أدمن، والعامة + ما يشمل
 * فرعه لمدير الفرع. لا عمود «فروع»: النطاق يُحدَّد من الشاشة لا من الملف.
 */
class ProductCategoriesExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /** @param  array<string, mixed>  $filters  فلاتر الشاشة — ProductCategory::scopeFilteredBy() */
    public function __construct(private readonly array $filters = [], private readonly ?int $branchId = null) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['الاسم', 'نشط'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return ProductCategory::query()
            ->visibleToBranch($this->branchId)
            ->filteredBy($this->filters)
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $category) => [
                $category->name,
                $category->is_active ? 1 : 0,
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
