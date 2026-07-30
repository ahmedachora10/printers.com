<?php

namespace App\Http\Controllers;

use App\Actions\Report\BuildReportDayRange;
use App\Actions\Report\ResolveReportScope;
use App\Enums\InvoiceStatusEnum;
use App\Exports\SalesReportExport;
use App\Http\Requests\Report\SalesReportFilterRequest;
use App\Models\Branch;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesReportController extends Controller
{
    /** Sum of every discount column, shared by totals and breakdowns. */
    private const DISCOUNTS = '(tier_discount_amount + coupon_discount + points_discount + agent_discount)';

    public function __construct(private readonly BuildReportDayRange $dayRange) {}

    public function index(SalesReportFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $resolveScope->handle($request);
        $type = $request->input('type', 'all');

        return Inertia::render('reports/sales/index', [
            'totals' => $this->totals($scope, $type),
            'byType' => $this->byType($scope, $type),
            'byDay' => $this->byDay($scope, $type),
            'byEmployee' => $this->byEmployee($scope, $type),
            'byPaymentMethod' => $this->byPaymentMethod($scope, $type),
            'byBranch' => $scope['isSuper'] ? $this->byBranch($scope, $type) : [],
            'filters' => [
                'from' => $scope['from']?->toDateString(),
                'to' => $scope['to']?->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'type' => $type,
            ],
            // The "cleared" value of the date fields — the report opens on today,
            // so the client treats today as the default and not as a live filter.
            'defaultDate' => Carbon::today()->toDateString(),
            'branches' => $scope['isSuper']
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(SalesReportFilterRequest $request, ResolveReportScope $resolveScope): BinaryFileResponse|HttpResponse
    {
        $scope = $resolveScope->handle($request);
        $type = $request->input('type', 'all');

        return Excel::download(
            new SalesReportExport($this->detailInvoices($scope, $type)),
            'sales-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * Tables to aggregate for the requested invoice type.
     *
     * @return array<int, string>
     */
    private function tablesForType(string $type): array
    {
        return match ($type) {
            'product' => ['product_invoices'],
            'service' => ['service_invoices'],
            default => ['product_invoices', 'service_invoices'],
        };
    }

    /**
     * Base query for one invoice table with all scope filters applied. Because
     * DB::table() bypasses the SoftDeletes global scope, deleted rows are
     * excluded explicitly. Only paid invoices count as realized revenue, dated
     * by paid_at.
     *
     * @param  array<string, mixed>  $scope
     */
    private function baseQuery(string $table, array $scope): Builder
    {
        return DB::table($table)
            ->where($table.'.status', InvoiceStatusEnum::PAID->value)
            ->whereNull($table.'.deleted_at')
            ->when($scope['branchId'], fn ($q) => $q->where($table.'.branch_id', $scope['branchId']))
            ->when($scope['from'], fn ($q) => $q->where($table.'.paid_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where($table.'.paid_at', '<=', $scope['to']));
    }

    /**
     * Grand totals across both invoice tables.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, float|int>
     */
    private function totals(array $scope, string $type): array
    {
        $subtotal = $discounts = $vat = $total = 0.0;
        $count = 0;

        foreach ($this->tablesForType($type) as $table) {
            $row = $this->baseQuery($table, $scope)->first([
                DB::raw('COUNT(*) as c'),
                DB::raw('COALESCE(SUM(subtotal), 0) as subtotal'),
                DB::raw('COALESCE(SUM('.self::DISCOUNTS.'), 0) as discounts'),
                DB::raw('COALESCE(SUM(vat_amount), 0) as vat'),
                DB::raw('COALESCE(SUM(total_amount), 0) as total'),
            ]);

            $count += (int) $row->c;
            $subtotal += (float) $row->subtotal;
            $discounts += (float) $row->discounts;
            $vat += (float) $row->vat;
            $total += (float) $row->total;
        }

        return [
            'invoiceCount' => $count,
            'subtotal' => $subtotal,
            'discounts' => $discounts,
            'vat' => $vat,
            'total' => $total,
        ];
    }

    /**
     * One row per invoice type (product / service).
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byType(array $scope, string $type): array
    {
        $labels = ['product_invoices' => 'منتجات', 'service_invoices' => 'خدمات'];
        $rows = [];

        foreach ($this->tablesForType($type) as $table) {
            $row = $this->baseQuery($table, $scope)->first([
                DB::raw('COUNT(*) as c'),
                DB::raw('COALESCE(SUM(subtotal), 0) as subtotal'),
                DB::raw('COALESCE(SUM('.self::DISCOUNTS.'), 0) as discounts'),
                DB::raw('COALESCE(SUM(vat_amount), 0) as vat'),
                DB::raw('COALESCE(SUM(total_amount), 0) as total'),
            ]);

            $rows[] = [
                'type' => $table === 'product_invoices' ? 'product' : 'service',
                'label' => $labels[$table],
                'count' => (int) $row->c,
                'subtotal' => (float) $row->subtotal,
                'discounts' => (float) $row->discounts,
                'vat' => (float) $row->vat,
                'total' => (float) $row->total,
            ];
        }

        return $rows;
    }

    /**
     * Daily revenue trend, merged across both tables and sorted chronologically.
     * Every day of the filtered range is listed, quiet ones included, so a range
     * shows its full calendar rather than only the days that sold something.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byDay(array $scope, string $type): array
    {
        $days = [];

        foreach ($this->dayRange->handle($scope) as $day) {
            $days[$day] = ['date' => $day, 'count' => 0, 'total' => 0.0];
        }

        foreach ($this->tablesForType($type) as $table) {
            $rows = $this->baseQuery($table, $scope)
                ->groupBy(DB::raw('DATE(paid_at)'))
                ->get([
                    DB::raw('DATE(paid_at) as day'),
                    DB::raw('COUNT(*) as c'),
                    DB::raw('COALESCE(SUM(total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = (string) $row->day;
                $days[$key] ??= ['date' => $key, 'count' => 0, 'total' => 0.0];
                $days[$key]['count'] += (int) $row->c;
                $days[$key]['total'] += (float) $row->total;
            }
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * Revenue per employee (invoice creator), merged across both tables.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byEmployee(array $scope, string $type): array
    {
        $employees = [];

        foreach ($this->tablesForType($type) as $table) {
            $rows = $this->baseQuery($table, $scope)
                ->join('users', 'users.id', '=', $table.'.user_id')
                ->groupBy($table.'.user_id', 'users.name')
                ->get([
                    $table.'.user_id as user_id',
                    'users.name as user_name',
                    DB::raw('COUNT(*) as c'),
                    DB::raw('COALESCE(SUM('.$table.'.total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = (int) $row->user_id;
                $employees[$key] ??= ['userId' => $key, 'userName' => $row->user_name, 'count' => 0, 'total' => 0.0];
                $employees[$key]['count'] += (int) $row->c;
                $employees[$key]['total'] += (float) $row->total;
            }
        }

        return $this->sortedByTotal($employees);
    }

    /**
     * Revenue per payment method. Invoices without one are grouped as "unspecified".
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byPaymentMethod(array $scope, string $type): array
    {
        $methods = [];

        foreach ($this->tablesForType($type) as $table) {
            $rows = $this->baseQuery($table, $scope)
                ->leftJoin('payment_methods', 'payment_methods.id', '=', $table.'.payment_method_id')
                ->groupBy($table.'.payment_method_id', 'payment_methods.name')
                ->get([
                    $table.'.payment_method_id as method_id',
                    'payment_methods.name as method_name',
                    DB::raw('COUNT(*) as c'),
                    DB::raw('COALESCE(SUM('.$table.'.total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = $row->method_id !== null ? (int) $row->method_id : 0;
                $methods[$key] ??= [
                    'methodId' => $row->method_id !== null ? (int) $row->method_id : null,
                    'methodName' => $row->method_name ?? 'غير محدد',
                    'count' => 0,
                    'total' => 0.0,
                ];
                $methods[$key]['count'] += (int) $row->c;
                $methods[$key]['total'] += (float) $row->total;
            }
        }

        return $this->sortedByTotal($methods);
    }

    /**
     * Revenue per branch (super-admin only), merged across both tables.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byBranch(array $scope, string $type): array
    {
        $branches = [];

        foreach ($this->tablesForType($type) as $table) {
            $rows = $this->baseQuery($table, $scope)
                ->join('branches', 'branches.id', '=', $table.'.branch_id')
                ->groupBy($table.'.branch_id', 'branches.name')
                ->get([
                    $table.'.branch_id as branch_id',
                    'branches.name as branch_name',
                    DB::raw('COUNT(*) as c'),
                    DB::raw('COALESCE(SUM('.$table.'.total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = (int) $row->branch_id;
                $branches[$key] ??= ['branchId' => $key, 'branchName' => $row->branch_name, 'count' => 0, 'total' => 0.0];
                $branches[$key]['count'] += (int) $row->c;
                $branches[$key]['total'] += (float) $row->total;
            }
        }

        return $this->sortedByTotal($branches);
    }

    /**
     * Per-invoice detail rows for the Excel export, merged and sorted by date.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function detailInvoices(array $scope, string $type): Collection
    {
        $rows = collect();

        foreach ($this->tablesForType($type) as $table) {
            $typeLabel = $table === 'product_invoices' ? 'منتجات' : 'خدمات';

            $records = $this->baseQuery($table, $scope)
                ->join('users', 'users.id', '=', $table.'.user_id')
                ->leftJoin('branches', 'branches.id', '=', $table.'.branch_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', $table.'.payment_method_id')
                ->get([
                    $table.'.invoice_number as invoice_number',
                    $table.'.paid_at as paid_at',
                    'branches.name as branch_name',
                    'users.name as user_name',
                    'payment_methods.name as method_name',
                    $table.'.subtotal as subtotal',
                    DB::raw(self::DISCOUNTS.' as discounts'),
                    $table.'.vat_amount as vat',
                    $table.'.total_amount as total',
                ]);

            foreach ($records as $r) {
                $rows->push([
                    'invoiceNumber' => $r->invoice_number,
                    'type' => $typeLabel,
                    'branchName' => $r->branch_name,
                    'userName' => $r->user_name,
                    'methodName' => $r->method_name ?? 'غير محدد',
                    'subtotal' => (float) $r->subtotal,
                    'discounts' => (float) $r->discounts,
                    'vat' => (float) $r->vat,
                    'total' => (float) $r->total,
                    'paidAt' => $r->paid_at ? Carbon::parse($r->paid_at)->toIso8601String() : null,
                ]);
            }
        }

        return $rows->sortBy('paidAt')->values();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortedByTotal(array $rows): array
    {
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_values($rows);
    }
}
