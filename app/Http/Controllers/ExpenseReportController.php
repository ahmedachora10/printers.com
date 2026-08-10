<?php

namespace App\Http\Controllers;

use App\Actions\Report\BuildReportDayRange;
use App\Actions\Report\ResolveReportScope;
use App\Exports\ExpenseReportExport;
use App\Http\Requests\Report\ExpenseReportFilterRequest;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تقرير المصروفات (تاسك 32) — القراءة المجمَّعة لشاشة الإدخال `/expenses`.
 *
 * وحدة التجميع هي المصروف نفسه، مؤرَّخاً بعمود `date` الذي يُدخله المستخدم — لا
 * بـ `created_at` — فمصروف يُسجَّل اليوم عن فاتورة مورّد بالأمس يقع في يوم
 * صرفه الحقيقي. جمهوره وحدود فرعه نفس تقرير المبيعات، عبر ResolveReportScope.
 */
class ExpenseReportController extends Controller
{
    public function __construct(private readonly BuildReportDayRange $dayRange) {}

    public function index(ExpenseReportFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $this->scope($request, $resolveScope);
        $totals = $this->totals($scope);
        $byCategory = $this->byCategory($scope, (float) $totals['total']);

        return Inertia::render('reports/expenses/index', [
            'totals' => [
                ...$totals,
                // أعلى فئة صرفاً — البطاقة الرابعة، وهي أول صفوف التفصيل.
                'topCategoryName' => $byCategory[0]['name'] ?? null,
                'topCategoryTotal' => $byCategory[0]['total'] ?? 0.0,
            ],
            'byCategory' => $byCategory,
            'byDay' => $this->byDay($scope),
            'expenses' => $this->detailRows($scope),
            'filters' => [
                'from' => $scope['from']?->toDateString(),
                'to' => $scope['to']?->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'category' => $scope['categoryId'] ? (string) $scope['categoryId'] : null,
            ],
            // The "cleared" value of the date fields — the report opens on today,
            // so the client treats today as the default and not as a live filter.
            'defaultDate' => Carbon::today()->toDateString(),
            'branches' => $scope['isSuper']
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            // فئات المصروفات جدول عام بلا branch_id — تُعرض كاملةً لكل فرع، تماماً
            // كما تفعل شاشة الإدخال /expenses.
            'categories' => ExpenseCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(ExpenseReportFilterRequest $request, ResolveReportScope $resolveScope): BinaryFileResponse|HttpResponse
    {
        $scope = $this->scope($request, $resolveScope);

        return Excel::download(
            new ExpenseReportExport($this->detailRows($scope)),
            'expense-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * The shared report scope plus this report's own category filter.
     *
     * @return array{isSuper: bool, branchId: ?int, categoryId: ?int, from: Carbon, to: Carbon}
     */
    private function scope(ExpenseReportFilterRequest $request, ResolveReportScope $resolveScope): array
    {
        return [
            ...$resolveScope->handle($request),
            'categoryId' => $request->filled('category') ? (int) $request->input('category') : null,
        ];
    }

    /**
     * Every expense inside the scope. Dated by `date` (the day the money was
     * spent), not by created_at.
     *
     * DB::table() bypasses the SoftDeletes global scope, so deleted rows are
     * excluded explicitly.
     *
     * @param  array<string, mixed>  $scope
     */
    private function baseQuery(array $scope): Builder
    {
        return DB::table('expenses')
            ->whereNull('expenses.deleted_at')
            ->when($scope['branchId'], fn ($q) => $q->where('expenses.branch_id', $scope['branchId']))
            ->when($scope['categoryId'], fn ($q) => $q->where('expenses.expense_category_id', $scope['categoryId']))
            ->when($scope['from'], fn ($q) => $q->whereDate('expenses.date', '>=', $scope['from']->toDateString()))
            ->when($scope['to'], fn ($q) => $q->whereDate('expenses.date', '<=', $scope['to']->toDateString()));
    }

    /**
     * Summary tiles: total spent, how many entries made it up, and the average
     * entry.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, float|int>
     */
    private function totals(array $scope): array
    {
        $row = $this->baseQuery($scope)->first([
            DB::raw('COUNT(*) as c'),
            DB::raw('COALESCE(SUM(expenses.total), 0) as total'),
        ]);

        $count = (int) $row->c;
        $total = (float) $row->total;

        return [
            'expenseCount' => $count,
            'total' => $total,
            'average' => $count > 0 ? round($total / $count, 2) : 0.0,
        ];
    }

    /**
     * Spend per expense category, biggest first, each with its share of the
     * grand total so the table reads without a calculator.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byCategory(array $scope, float $grandTotal): array
    {
        return $this->baseQuery($scope)
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->groupBy('expenses.expense_category_id', 'expense_categories.name')
            ->orderByDesc(DB::raw('SUM(expenses.total)'))
            ->get([
                'expenses.expense_category_id as category_id',
                'expense_categories.name as category_name',
                DB::raw('COUNT(*) as c'),
                DB::raw('COALESCE(SUM(expenses.total), 0) as total'),
            ])
            ->map(fn ($row) => [
                'categoryId' => $row->category_id !== null ? (int) $row->category_id : null,
                'name' => $row->category_name ?? 'غير مصنّف',
                'count' => (int) $row->c,
                'total' => (float) $row->total,
                'pct' => $grandTotal > 0 ? round((float) $row->total / $grandTotal * 100, 2) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Spend per day across the whole filtered range, quiet days included so the
     * table lists its full calendar rather than only the days money went out.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byDay(array $scope): array
    {
        $days = [];

        foreach ($this->dayRange->handle($scope) as $day) {
            $days[$day] = ['date' => $day, 'count' => 0, 'total' => 0.0];
        }

        $rows = $this->baseQuery($scope)
            ->groupBy(DB::raw('DATE(expenses.date)'))
            ->get([
                DB::raw('DATE(expenses.date) as day'),
                DB::raw('COUNT(*) as c'),
                DB::raw('COALESCE(SUM(expenses.total), 0) as total'),
            ]);

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $days[$day] = ['date' => $day, 'count' => (int) $row->c, 'total' => (float) $row->total];
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * Drill-down: one row per expense, newest first. Feeds both the on-screen
     * table and the Excel export, so the sheet can never disagree with the page.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function detailRows(array $scope): Collection
    {
        return $this->baseQuery($scope)
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->leftJoin('users', 'users.id', '=', 'expenses.user_id')
            ->leftJoin('branches', 'branches.id', '=', 'expenses.branch_id')
            ->orderByDesc('expenses.date')
            ->orderByDesc('expenses.id')
            ->get([
                'expenses.id as id',
                'expenses.date as date',
                'expense_categories.name as category_name',
                'branches.name as branch_name',
                'expenses.supplier_name as supplier_name',
                'expenses.qty as qty',
                'expenses.unit_price as unit_price',
                'expenses.total as total',
                'expenses.receipt_reference as receipt_reference',
                'users.name as user_name',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'date' => Carbon::parse($row->date)->toDateString(),
                'categoryName' => $row->category_name ?? 'غير مصنّف',
                'branchName' => $row->branch_name,
                'supplierName' => $row->supplier_name,
                'qty' => (float) $row->qty,
                'unitPrice' => (float) $row->unit_price,
                'total' => (float) $row->total,
                'receiptReference' => $row->receipt_reference,
                'userName' => $row->user_name,
            ])
            ->values();
    }
}
