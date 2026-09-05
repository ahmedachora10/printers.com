<?php

namespace App\Http\Controllers;

use App\Actions\Report\BuildReportDayRange;
use App\Actions\Report\ResolveReportScope;
use App\Enums\InvoiceStatusEnum;
use App\Exports\SalesReportExport;
use App\Http\Requests\Report\SalesReportFilterRequest;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
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
 * Realized-revenue sales report (M17).
 *
 * The unit of aggregation is a *collection event*, not an invoice: an invoice
 * settled by a deposit plus later payments (عربون + دفعات لاحقة) contributes one
 * row per recorded payment, each landing on the day the money actually came in.
 * An invoice settled outright at the till carries no payment rows at all, so it
 * contributes a single event worth its whole total, dated by paid_at — which is
 * exactly what this report has always counted.
 *
 * Every invoice-level figure (subtotal, discounts, VAT) is prorated by the share
 * of the total that the payment represents, so the identity
 * `subtotal − discounts + VAT = total` still holds on partial collections. For a
 * fully collected invoice the share is 1 and nothing changes.
 *
 * والمرتجع حدثُ تحصيلٍ سالب على المنوال نفسه: مالٌ خرج، مؤرَّخٌ بيوم خروجه،
 * موزَّعةٌ أرقامُه بحصّته من الفاتورة. فالإيراد المعروض صافٍ من المرتجعات، وتُعرض
 * جملتها إلى جانبه. أما الفاتورة المرتجعة بالكامل فقد سقطت أصلاً بحكم حالتها
 * (`returned` خارج COLLECTED_STATUSES)، فلا يُطرح مرتجعها فوق ذلك — وهو نفس
 * التمييز الذي يقيمه عمود `deductible` في التقرير اليومي.
 */
class SalesReportController extends Controller
{
    /** The statuses that have money behind them: fully or partly collected. */
    private const COLLECTED_STATUSES = [
        InvoiceStatusEnum::PAID->value,
        InvoiceStatusEnum::PARTIALLY_PAID->value,
    ];

    public function __construct(private readonly BuildReportDayRange $dayRange) {}

    /** Sum of every discount column of one invoice table, under an alias. */
    private function discounts(string $alias): string
    {
        return "({$alias}.tier_discount_amount + {$alias}.coupon_discount + {$alias}.points_discount + {$alias}.agent_discount)";
    }

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
     * Every collection event of one invoice table, with the scope filters
     * applied — the base of every figure in this report.
     *
     * Two sources are unioned:
     *  A. one row per recorded payment (deposit or instalment), dated by that
     *     payment and carrying its own payment method;
     *  B. one row per paid invoice that has no payment rows at all — settled in
     *     one go at the till — worth its whole total and dated by paid_at;
     *  C. one *negative* row per refund, dated by the day the money went back.
     *
     * Because DB::table() bypasses the SoftDeletes global scope, deleted rows
     * are excluded explicitly.
     *
     * @param  array<string, mixed>  $scope
     */
    private function baseQuery(string $table, array $scope): Builder
    {
        $morphClass = $this->morphClassFor($table);
        $discounts = $this->discounts('i');
        // حصة الدفعة من الفاتورة: تُوزَّع بها أرقامُ الفاتورة (الفرعي، الخصومات،
        // الضريبة) فتبقى المعادلة متسقة على التحصيل الجزئي. الضرب في 1.0 يفرض
        // قسمةً عشرية — SQLite يقسم الأعداد الصحيحة قسمةً صحيحة فتصير الحصة صفراً.
        $share = '(p.amount * 1.0) / NULLIF(i.total_amount, 0)';

        $payments = DB::table('invoice_payments as p')
            ->join($table.' as i', 'i.id', '=', 'p.invoice_id')
            ->where('p.invoice_type', $morphClass)
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', self::COLLECTED_STATUSES)
            ->select([
                DB::raw('i.id as invoice_id'),
                DB::raw('i.invoice_number as invoice_number'),
                DB::raw('i.branch_id as branch_id'),
                DB::raw('i.user_id as user_id'),
                DB::raw('COALESCE(p.payment_method_id, i.payment_method_id) as payment_method_id'),
                DB::raw('p.paid_at as realized_at'),
                DB::raw('p.amount as realized'),
                DB::raw("i.subtotal * ({$share}) as subtotal_share"),
                DB::raw("{$discounts} * ({$share}) as discounts_share"),
                DB::raw("i.vat_amount * ({$share}) as vat_share"),
            ]);

        $direct = DB::table($table.' as i')
            ->whereNull('i.deleted_at')
            ->where('i.status', InvoiceStatusEnum::PAID->value)
            ->whereNotExists(fn ($q) => $q->from('invoice_payments as p')
                ->where('p.invoice_type', $morphClass)
                ->whereColumn('p.invoice_id', 'i.id'))
            ->select([
                DB::raw('i.id as invoice_id'),
                DB::raw('i.invoice_number as invoice_number'),
                DB::raw('i.branch_id as branch_id'),
                DB::raw('i.user_id as user_id'),
                DB::raw('i.payment_method_id as payment_method_id'),
                DB::raw('i.paid_at as realized_at'),
                DB::raw('i.total_amount as realized'),
                DB::raw('i.subtotal as subtotal_share'),
                DB::raw("{$discounts} as discounts_share"),
                DB::raw('i.vat_amount as vat_share'),
            ]);

        // C. المرتجعات — أحداثٌ سالبة بحصّتها من الفاتورة. تُقصر على الفواتير
        // التي ما زالت محسوبةً هنا: الفاتورة المرتجعة بالكامل حالتها `returned`
        // فسقطت من الفرعين أعلاه، وطرحُ مرتجعها فوق ذلك خصمٌ ثانٍ لنفس المبلغ.
        $refundShare = '(r.amount * 1.0) / NULLIF(i.total_amount, 0)';

        $refunds = DB::table('refunds as r')
            ->join($table.' as i', 'i.id', '=', 'r.invoice_id')
            ->where('r.invoice_type', $morphClass)
            ->whereNull('r.deleted_at')
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', self::COLLECTED_STATUSES)
            ->select([
                DB::raw('i.id as invoice_id'),
                DB::raw('i.invoice_number as invoice_number'),
                DB::raw('i.branch_id as branch_id'),
                // يُنسب المرتجع إلى منشئ الفاتورة لا إلى من سجّله: الأثر على
                // مبيعات ذلك الموظف. نفس عُرف التقرير اليومي.
                DB::raw('i.user_id as user_id'),
                DB::raw('i.payment_method_id as payment_method_id'),
                DB::raw('r.created_at as realized_at'),
                DB::raw('-r.amount as realized'),
                DB::raw("-i.subtotal * ({$refundShare}) as subtotal_share"),
                DB::raw("-{$discounts} * ({$refundShare}) as discounts_share"),
                DB::raw("-i.vat_amount * ({$refundShare}) as vat_share"),
            ]);

        return DB::query()
            ->fromSub($payments->unionAll($direct)->unionAll($refunds), 'events')
            ->whereNotNull('events.realized_at')
            ->when($scope['branchId'], fn ($q) => $q->where('events.branch_id', $scope['branchId']))
            ->when($scope['from'], fn ($q) => $q->where('events.realized_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('events.realized_at', '<=', $scope['to']));
    }

    /** The morph class stored on invoice_payments for one invoice table. */
    private function morphClassFor(string $table): string
    {
        return $table === 'product_invoices' ? ProductInvoice::class : ServiceInvoice::class;
    }

    /**
     * عدد الفواتير: تُعدّ مرةً واحدة وإن امتدّت على عدة أحداث تحصيل، ولا يُعدّ
     * المرتجع فيها — وإلا لأضاف مرتجعُ فاتورةٍ بيعت في فترة سابقة فاتورةً لم
     * تُبَع في فترة المرتجع.
     */
    private const COUNT_EXPR = 'COUNT(DISTINCT CASE WHEN events.realized > 0 THEN events.invoice_id END)';

    /** جملة ما رُدّ للعملاء، موجبةً، إلى جانب الإيراد الصافي منها. */
    private const REFUNDS_EXPR = 'COALESCE(SUM(CASE WHEN events.realized < 0 THEN -events.realized ELSE 0 END), 0)';

    /**
     * The aggregate columns every breakdown selects — an invoice may span
     * several collection events, so it is counted once, distinctly.
     *
     * @return array<int, mixed>
     */
    private function sumColumns(): array
    {
        return [
            DB::raw(self::COUNT_EXPR.' as c'),
            DB::raw('COALESCE(SUM(events.subtotal_share), 0) as subtotal'),
            DB::raw('COALESCE(SUM(events.discounts_share), 0) as discounts'),
            DB::raw('COALESCE(SUM(events.vat_share), 0) as vat'),
            DB::raw('COALESCE(SUM(events.realized), 0) as total'),
            DB::raw(self::REFUNDS_EXPR.' as refunds'),
        ];
    }

    /**
     * Grand totals across both invoice tables.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, float|int>
     */
    private function totals(array $scope, string $type): array
    {
        $subtotal = $discounts = $vat = $total = $refunds = 0.0;
        $count = 0;

        foreach ($this->tablesForType($type) as $table) {
            $row = $this->baseQuery($table, $scope)->first($this->sumColumns());

            $count += (int) $row->c;
            $subtotal += (float) $row->subtotal;
            $discounts += (float) $row->discounts;
            $vat += (float) $row->vat;
            $total += (float) $row->total;
            $refunds += (float) $row->refunds;
        }

        return [
            'invoiceCount' => $count,
            'subtotal' => $subtotal,
            'discounts' => $discounts,
            'vat' => $vat,
            'refunds' => $refunds,
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
            $row = $this->baseQuery($table, $scope)->first($this->sumColumns());

            $rows[] = [
                'type' => $table === 'product_invoices' ? 'product' : 'service',
                'label' => $labels[$table],
                'count' => (int) $row->c,
                'subtotal' => (float) $row->subtotal,
                'discounts' => (float) $row->discounts,
                'vat' => (float) $row->vat,
                'refunds' => (float) $row->refunds,
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
                ->groupBy(DB::raw('DATE(events.realized_at)'))
                ->get([
                    DB::raw('DATE(events.realized_at) as day'),
                    DB::raw(self::COUNT_EXPR.' as c'),
                    DB::raw('COALESCE(SUM(events.realized), 0) as total'),
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
                ->join('users', 'users.id', '=', 'events.user_id')
                ->groupBy('events.user_id', 'users.name')
                ->get([
                    DB::raw('events.user_id as user_id'),
                    'users.name as user_name',
                    DB::raw(self::COUNT_EXPR.' as c'),
                    DB::raw('COALESCE(SUM(events.realized), 0) as total'),
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
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'events.payment_method_id')
                ->groupBy('events.payment_method_id', 'payment_methods.name')
                ->get([
                    DB::raw('events.payment_method_id as method_id'),
                    'payment_methods.name as method_name',
                    DB::raw(self::COUNT_EXPR.' as c'),
                    DB::raw('COALESCE(SUM(events.realized), 0) as total'),
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
                ->join('branches', 'branches.id', '=', 'events.branch_id')
                ->groupBy('events.branch_id', 'branches.name')
                ->get([
                    DB::raw('events.branch_id as branch_id'),
                    'branches.name as branch_name',
                    DB::raw(self::COUNT_EXPR.' as c'),
                    DB::raw('COALESCE(SUM(events.realized), 0) as total'),
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
     * Detail rows for the Excel export, merged and sorted by date — one row per
     * collection event, so an invoice paid off in instalments lists each of them
     * under the same invoice number, and a refund lists as a negative row of its
     * own under عمود «الحركة».
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
                ->join('users', 'users.id', '=', 'events.user_id')
                ->leftJoin('branches', 'branches.id', '=', 'events.branch_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'events.payment_method_id')
                ->get([
                    DB::raw('events.invoice_number as invoice_number'),
                    DB::raw('events.realized_at as paid_at'),
                    'branches.name as branch_name',
                    'users.name as user_name',
                    'payment_methods.name as method_name',
                    DB::raw('events.subtotal_share as subtotal'),
                    DB::raw('events.discounts_share as discounts'),
                    DB::raw('events.vat_share as vat'),
                    DB::raw('events.realized as total'),
                ]);

            foreach ($records as $r) {
                $rows->push([
                    'invoiceNumber' => $r->invoice_number,
                    'type' => $typeLabel,
                    // الحدث السالب مرتجع، وما عداه تحصيل — فلا يقرأ القارئ رقماً
                    // سالباً في ورقةٍ بلا تفسير.
                    'kind' => (float) $r->total < 0 ? 'مرتجع' : 'تحصيل',
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
