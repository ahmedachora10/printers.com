<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * تاسك 72: منتجات فرعٍ واحد — أو كل الفروع للسوبر أدمن حين لا يختار فرعاً —
 * في ورقةٍ تعود بالاستيراد كما خرجت: المفتاح `sku` داخل الفرع، فدورة
 * «تصدير ← تعديل ← استيراد» آمنة.
 *
 * ⚠️ «المخزون الحالي» عمودٌ **للقراءة وحده**: `products.current_stock` محسوب من
 * `stock_movements` ولا يُكتب إلا من `recalculateStock()`. يُصدَّر ليقرأه صاحب
 * الملف، ويتجاهله الاستيراد صراحةً ويقول ذلك في تقرير المعاينة.
 */
class ProductsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(private readonly ?int $branchId = null) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'SKU',
            'اسم المنتج',
            'الفئة',
            'الوحدة',
            'سعر التكلفة',
            'سعر البيع',
            'الحد الأدنى',
            'الباركود',
            'بالمتر المربع',
            'نشط',
            'الفرع',
            'المخزون الحالي',
        ];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        return Product::query()
            ->with(['category:id,name', 'unit:id,name', 'branch:id,name'])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                $product->sku,
                $product->name,
                $product->category?->name,
                $product->unit?->name,
                number_format((float) $product->cost_price, 2, '.', ''),
                number_format((float) $product->selling_price, 2, '.', ''),
                number_format((float) $product->min_stock_level, 2, '.', ''),
                $product->barcode,
                $product->is_sqm ? 'نعم' : 'لا',
                $product->is_active ? 1 : 0,
                $product->branch?->name,
                number_format((float) $product->current_stock, 2, '.', ''),
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
