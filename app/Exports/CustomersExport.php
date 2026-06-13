<?php

namespace App\Exports;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomersExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(private readonly ?int $branchId = null) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['الاسم', 'الهاتف', 'النوع', 'المستوى', 'عدد الفواتير', 'إجمالي الإنفاق', 'آخر زيارة'];
    }

    /** @return Collection<int, mixed> */
    public function collection(): Collection
    {
        $customers = Customer::query()
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->orderBy('full_name')
            ->get();

        if ($customers->isEmpty()) {
            return collect();
        }

        $ids = $customers->pluck('id');

        [$serviceCounts, $serviceLastAt] = $this->loadInvoiceStats('service_invoices', $ids);
        [$productCounts, $productLastAt] = $this->loadInvoiceStats('product_invoices', $ids);

        return $customers->map(function (Customer $customer) use ($serviceCounts, $serviceLastAt, $productCounts, $productLastAt) {
            $invoiceCount = ($serviceCounts[$customer->id] ?? 0) + ($productCounts[$customer->id] ?? 0);

            $dates = array_filter([
                $serviceLastAt[$customer->id] ?? null,
                $productLastAt[$customer->id] ?? null,
            ]);
            $lastVisit = ! empty($dates)
                ? Carbon::parse(max($dates))->format('d/m/Y')
                : '—';

            return [
                $customer->full_name,
                $customer->phone,
                $customer->customer_type->label(),
                $customer->tier->label(),
                $invoiceCount,
                number_format((float) $customer->cumulative_spend, 2),
                $lastVisit,
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array{array<int,int>, array<int,string|null>}
     */
    private function loadInvoiceStats(string $table, Collection $ids): array
    {
        if (! Schema::hasTable($table)) {
            return [[], []];
        }

        $rows = DB::table($table)
            ->whereIn('customer_id', $ids)
            ->whereNull('deleted_at')
            ->groupBy('customer_id')
            ->select('customer_id', DB::raw('COUNT(*) as cnt'), DB::raw('MAX(created_at) as last_at'))
            ->get();

        return [
            $rows->pluck('cnt', 'customer_id')->all(),
            $rows->pluck('last_at', 'customer_id')->all(),
        ];
    }
}
