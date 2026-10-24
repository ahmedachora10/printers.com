<?php

namespace App\Http\Controllers;

use App\Actions\Report\BuildReportDayRange;
use App\Actions\Report\ExcludeReturnedCommission;
use App\Enums\CommissionSourceTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Exports\CommissionReportExport;
use App\Http\Requests\Report\CommissionReportFilterRequest;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceHelper;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CommissionReportController extends Controller
{
    public function __construct(private readonly BuildReportDayRange $dayRange) {}

    public function index(CommissionReportFilterRequest $request): Response
    {
        $scope = $this->resolveScope($request);

        $summary = $this->summaryRows($scope);
        $lines = $this->detailLines($scope);
        $byDay = $this->dailyRows($scope);

        return Inertia::render('reports/commissions/index', [
            'summary' => $summary,
            'byDay' => $byDay,
            'lines' => $lines,
            'totals' => [
                // تاسك 133: الإيراد يومي المحور (يوم الاعتماد)، فمجموع الأيام هو إجماليه.
                'revenue' => round($byDay->sum('revenue'), 2),
                'collected' => round($byDay->sum('collected'), 2),
                'vat' => round($byDay->sum('vat'), 2),
                'invoiceCount' => $byDay->sum('invoiceCount'),
                'afterMaterials' => round($byDay->sum('afterMaterials'), 2),
                // بطاقة «إجمالي الإيرادات» (لقطة العميل): بعد الخامات و**كل** العمولات —
                // تزيد على عمود afterMaterials بطرح عمولة الموظف أيضاً (1000 ⇒ 825).
                'netRevenue' => round($byDay->sum('afterMaterials') - $byDay->sum('earned'), 2),
                'earned' => (float) $summary->sum('earned'),
                'paid' => (float) $summary->sum('paid'),
                'pending' => (float) $summary->sum('pending'),
                'tahazir' => (float) $summary->sum('tahazir'),
                'lineCommission' => (float) $summary->sum('lineCommission'),
                'materials' => (float) $summary->sum('materials'),
                'lineCount' => $lines->count(),
            ],
            'filters' => [
                'from' => $scope['from']?->toDateString(),
                'to' => $scope['to']?->toDateString(),
                'employee' => $scope['forcedUserId'] ? null : ($scope['userId'] ? (string) $scope['userId'] : null),
                'branch' => $scope['forcedBranchId'] ? null : ($scope['branchId'] ? (string) $scope['branchId'] : null),
                'status' => $scope['status'],
            ],
            // The "cleared" value of the date fields — the report opens on today,
            // so the client treats today as the default and not as a live filter.
            'defaultDate' => Carbon::today()->toDateString(),
            'employees' => $scope['forcedUserId'] ? [] : $this->employeeOptions($scope['branchId']),
            'branches' => $scope['forcedBranchId']
                ? []
                : Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(CommissionReportFilterRequest $request): BinaryFileResponse|HttpResponse
    {
        $scope = $this->resolveScope($request);

        return Excel::download(
            new CommissionReportExport($this->detailLines($scope)),
            'commission-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * Resolve the effective filter scope, folding role restrictions into the
     * raw request filters. Employees are locked to their own rows; branch-admins
     * and the auditor to their own branch. Only super-admin may pick freely.
     *
     * @return array{isSuper: bool, branchId: ?int, userId: ?int, forcedBranchId: bool, forcedUserId: bool, from: ?Carbon, to: ?Carbon, status: string}
     */
    private function resolveScope(CommissionReportFilterRequest $request): array
    {
        $actor = $request->user();
        $role = $actor->roleName;

        abort_if($role === Roles::AGENT, 403);

        $isSuper = $role?->isSuperAdmin() ?? false;
        $isEmployee = $role?->isEmployee() ?? false;

        // Branch: super-admin picks; everyone else is pinned to their own branch.
        $forcedBranchId = ! $isSuper;
        $branchId = $isSuper
            ? ($request->filled('branch') ? (int) $request->input('branch') : null)
            : $actor->branchId;

        // Employee: pinned to their own ledger rows.
        $forcedUserId = $isEmployee;
        $userId = $isEmployee
            ? $actor->id
            : ($request->filled('employee') ? (int) $request->input('employee') : null);

        return [
            'isSuper' => $isSuper,
            'branchId' => $branchId,
            'userId' => $userId,
            'forcedBranchId' => $forcedBranchId,
            'forcedUserId' => $forcedUserId,
            // Unfiltered, the report shows TODAY only — widening the window is
            // the filter modal's job. Mirrors ResolveReportScope.
            'from' => $request->filled('from')
                ? Carbon::parse($request->input('from'))->startOfDay()
                : Carbon::today()->startOfDay(),
            'to' => $request->filled('to')
                ? Carbon::parse($request->input('to'))->endOfDay()
                : Carbon::today()->endOfDay(),
            'status' => $request->input('status', 'all'),
        ];
    }

    /**
     * Base ledger query with all scope filters applied. Shared by summary and
     * detail so both views agree exactly.
     *
     * @param  array<string, mixed>  $scope
     */
    private function baseQuery(array $scope): Builder
    {
        return DB::table('commission_ledger')
            ->when($scope['branchId'], fn ($q) => $q->where('commission_ledger.branch_id', $scope['branchId']))
            ->when($scope['userId'], fn ($q) => $q->where('commission_ledger.user_id', $scope['userId']))
            ->when($scope['from'], fn ($q) => $q->where('commission_ledger.earned_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('commission_ledger.earned_at', '<=', $scope['to']))
            ->when($scope['status'] === 'paid', fn ($q) => $q->whereNotNull('commission_ledger.paid_at'))
            ->when($scope['status'] === 'pending', fn ($q) => $q->whereNull('commission_ledger.paid_at'))
            // "مرتجعة" is a view of what was reversed, so it keeps exactly the rows
            // every other view drops.
            ->when($scope['status'] === 'returned',
                fn ($q) => $q->whereExists(fn ($sub) => $this->returnedInvoiceSubquery($sub)));
    }

    /**
     * Aggregate query: the ledger rows that still represent money, with the
     * unpaid commission of returned invoices left out (تاسك 10). القاعدة نفسها
     * يستدعيها التقرير اليومي عبر ExcludeReturnedCommission، فلا يفترقان.
     *
     * @param  array<string, mixed>  $scope
     */
    private function moneyQuery(array $scope): Builder
    {
        return $this->baseQuery($scope)
            ->when($scope['status'] !== 'returned',
                fn ($q) => ExcludeReturnedCommission::apply($q));
    }

    /**
     * Correlated subquery: does this ledger row belong to a returned invoice?
     */
    private function returnedInvoiceSubquery(Builder $query): Builder
    {
        return ExcludeReturnedCommission::returnedInvoice($query);
    }

    /**
     * Per-employee aggregate rows.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function summaryRows(array $scope): Collection
    {
        $lineCommissions = $this->lineCommissionByEmployee($scope)->keyBy('userId');
        $revenue = $this->sumByEmployee($this->approvedInvoiceQuery(DB::table('service_invoices'), $scope), 'service_invoices.user_id', 'service_invoices.total_amount');
        $refunds = $this->sumByEmployee($this->refundsQuery($scope), 'service_invoices.user_id', 'r.amount');
        $collected = $this->sumByEmployee($this->collectedQuery($scope), 'c.user_id', 'c.amount');

        // Columns read off the employee's own approved invoices, not the ledger.
        $invoiceSide = fn (int $userId) => [
            // تاسك 174: نفس dailyRows مجمّعاً بالموظف، فيتطابق مجموعا الجدولين.
            'revenue' => round(($revenue[$userId]['amount'] ?? 0.0) - ($refunds[$userId]['amount'] ?? 0.0), 2),
            'collected' => $collected[$userId]['amount'] ?? 0.0,
            'lineCommission' => $lineCommissions[$userId]['amount'] ?? 0.0,
            'materials' => $lineCommissions[$userId]['materials'] ?? 0.0,
        ];

        $rows = $this->ledgerSummaryRows($scope)
            ->map(fn (array $row) => [...$row, ...$invoiceSide($row['userId'])]);

        // An employee whose invoices earned no ledger row (only agent commissions,
        // or nothing at all) is listed too, so each column total matches its rows.
        // A payment or refund on an invoice approved before the range counts too.
        $missing = $lineCommissions->union($revenue)->union($refunds)->union($collected)
            ->reject(fn (array $row) => $rows->contains('userId', $row['userId']))
            ->map(fn (array $row) => [
                'userId' => $row['userId'],
                'userName' => $row['userName'],
                'lineCount' => 0,
                'earned' => 0.0,
                'paid' => 0.0,
                'pending' => 0.0,
                'tahazir' => 0.0,
                ...$invoiceSide($row['userId']),
            ]);

        return $rows->concat($missing->values())->sortBy('userName')->values();
    }

    /**
     * Per-employee aggregate rows straight off the ledger.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function ledgerSummaryRows(array $scope): Collection
    {
        return $this->moneyQuery($scope)
            ->join('users', 'users.id', '=', 'commission_ledger.user_id')
            ->groupBy('commission_ledger.user_id', 'users.name')
            ->orderBy('users.name')
            ->get([
                'commission_ledger.user_id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(commission_ledger.amount), 0) as earned'),
                DB::raw('COALESCE(SUM(CASE WHEN commission_ledger.paid_at IS NOT NULL THEN commission_ledger.amount ELSE 0 END), 0) as paid'),
                DB::raw('COALESCE(SUM(CASE WHEN commission_ledger.is_tahazir = 1 THEN commission_ledger.amount ELSE 0 END), 0) as tahazir'),
            ])
            ->map(function ($row) {
                $earned = (float) $row->earned;
                $paid = (float) $row->paid;

                return [
                    'userId' => (int) $row->user_id,
                    'userName' => $row->user_name,
                    'lineCount' => (int) $row->line_count,
                    'earned' => $earned,
                    'paid' => $paid,
                    'pending' => max(0, $earned - $paid),
                    'tahazir' => (float) $row->tahazir,
                ];
            })
            ->values();
    }

    /**
     * Per-day aggregate rows covering the whole filtered range. Days without any
     * ledger entry are kept and read 0.00, so a multi-day filter lists its full
     * calendar rather than only the days that earned something.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function dailyRows(array $scope): Collection
    {
        $lineCommissions = $this->lineCommissionByDay($scope);
        $revenue = $this->revenueByDay($scope);
        $collected = $this->collectedByDay($scope);
        $refunds = $this->refundsByDay($scope);
        $days = [];

        foreach ($this->dayRange->handle($scope) as $day) {
            $days[$day] = [
                'date' => $day,
                'lineCount' => 0,
                'earned' => 0.0,
                'paid' => 0.0,
                'pending' => 0.0,
                'tahazir' => 0.0,
            ];
        }

        $rows = $this->moneyQuery($scope)
            ->groupBy(DB::raw('DATE(commission_ledger.earned_at)'))
            ->get([
                DB::raw('DATE(commission_ledger.earned_at) as day'),
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(commission_ledger.amount), 0) as earned'),
                DB::raw('COALESCE(SUM(CASE WHEN commission_ledger.paid_at IS NOT NULL THEN commission_ledger.amount ELSE 0 END), 0) as paid'),
                DB::raw('COALESCE(SUM(CASE WHEN commission_ledger.is_tahazir = 1 THEN commission_ledger.amount ELSE 0 END), 0) as tahazir'),
            ]);

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $earned = (float) $row->earned;
            $paid = (float) $row->paid;

            $days[$day] = [
                'date' => $day,
                'lineCount' => (int) $row->line_count,
                'earned' => $earned,
                'paid' => $paid,
                'pending' => max(0, $earned - $paid),
                'tahazir' => (float) $row->tahazir,
            ];
        }

        foreach ($days as $day => $row) {
            // المرتجع يُطرح يوم تسجيله من «الإجمالي» (ملاحظات العميل 23/10/2026).
            $total = round(($revenue[$day]['total'] ?? 0.0) - ($refunds[$day] ?? 0.0), 2);
            $lineCommission = $lineCommissions[$day]['amount'] ?? 0.0;
            $materials = $lineCommissions[$day]['materials'] ?? 0.0;

            $days[$day] = [
                ...$row,
                'invoiceCount' => $revenue[$day]['count'] ?? 0,
                'revenue' => $total,
                'collected' => $collected[$day]['amount'] ?? 0.0,
                // ضريبة ما حُصِّل، كعمود الضريبة في التقرير اليومي (تاسك 139).
                'vat' => $collected[$day]['vat'] ?? 0.0,
                'lineCommission' => $lineCommission,
                'materials' => $materials,
                // تاسك 133 — مثال العميل: 1000 − 25 خامات − 50 خارجية = 925.
                // عمولة الموظف لا تُطرح هنا.
                'afterMaterials' => round($total - $materials - $lineCommission, 2),
            ];
        }

        ksort($days);

        return collect(array_values($days));
    }

    /**
     * Line-level detail: one row per ledger entry, resolved to its service
     * invoice for drill-down (invoice number, service name, date).
     *
     * Unlike the aggregates this keeps the rows of returned invoices — they carry
     * the "مرتجعة" badge, which is what explains why they are missing from the
     * totals above them.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function detailLines(array $scope): Collection
    {
        // الصفّ لسطرٍ (صاحب الفاتورة) أو لمساعد — والفاتورة من أيّهما وُجد.
        return $this->baseQuery($scope)
            ->whereIn('commission_ledger.invoice_line_type', [ServiceInvoiceLine::class, ServiceInvoiceHelper::class])
            ->join('users', 'users.id', '=', 'commission_ledger.user_id')
            ->leftJoin('service_invoice_lines', fn ($j) => $j
                ->on('service_invoice_lines.id', '=', 'commission_ledger.invoice_line_id')
                ->where('commission_ledger.invoice_line_type', ServiceInvoiceLine::class))
            ->leftJoin('service_invoice_helpers', fn ($j) => $j
                ->on('service_invoice_helpers.id', '=', 'commission_ledger.invoice_line_id')
                ->where('commission_ledger.invoice_line_type', ServiceInvoiceHelper::class))
            ->join('service_invoices', 'service_invoices.id', '=', DB::raw('COALESCE(service_invoice_lines.invoice_id, service_invoice_helpers.service_invoice_id)'))
            ->orderByDesc('commission_ledger.earned_at')
            ->orderByDesc('commission_ledger.id')
            ->select([
                'commission_ledger.id as id',
                'commission_ledger.user_id as user_id',
                'users.name as user_name',
                'service_invoices.id as invoice_id',
                'service_invoices.invoice_number as invoice_number',
                'service_invoices.total_amount as invoice_total',
                'service_invoices.status as invoice_status',
                DB::raw('COALESCE(service_invoice_lines.service_name, service_invoice_helpers.service_name) as service_name'),
                DB::raw('COALESCE(service_invoice_lines.agent_commission_amount, 0) as agent_commission_amount'),
                DB::raw('COALESCE(service_invoice_lines.materials_total, 0) as materials_total'),
                'commission_ledger.invoice_line_type as invoice_line_type',
                'commission_ledger.amount as amount',
                'commission_ledger.is_tahazir as is_tahazir',
                'commission_ledger.tier_applied as tier_applied',
                'commission_ledger.source_type as source_type',
                'commission_ledger.earned_at as earned_at',
                'commission_ledger.paid_at as paid_at',
            ])
            // ما حُصِّل على الفاتورة حتى الآن — منطق HasInvoicePayments::paidAmount():
            // دفعاتها، وإلا إجماليها إن سُدِّدت عند البيع بلا صفوف دفعات.
            ->selectRaw(
                'COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_type = ? AND ip.invoice_id = service_invoices.id),'
                .' CASE WHEN service_invoices.status = ? THEN service_invoices.total_amount ELSE 0 END) as invoice_collected',
                [ServiceInvoice::class, InvoiceStatusEnum::PAID->value],
            )
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'userId' => (int) $row->user_id,
                'userName' => $row->user_name,
                'invoiceId' => (int) $row->invoice_id,
                'invoiceNumber' => $row->invoice_number,
                // تاسك 175: للمرجعية — يتكرّر على كل بنود الفاتورة فلا يُجمع.
                'invoiceTotal' => (float) $row->invoice_total,
                'invoiceCollected' => (float) $row->invoice_collected,
                'invoiceStatus' => $row->invoice_status,
                'serviceName' => $row->service_name,
                // The مندوب's share of this same line — shown beside the
                // employee's commission, never added to it.
                'lineCommission' => (float) $row->agent_commission_amount,
                // تكلفة خامات هذا السطر — خُصمت أصلاً من مبلغ العمولة المجاور،
                // وتُعرض للتوضيح فقط.
                'materials' => (float) $row->materials_total,
                'amount' => (float) $row->amount,
                'isTahazir' => (bool) $row->is_tahazir,
                'tierApplied' => $row->tier_applied !== null ? (int) $row->tier_applied : null,
                'sourceType' => $row->source_type,
                'sourceLabel' => $row->invoice_line_type === ServiceInvoiceHelper::class
                    ? 'عمولة مساعدة'
                    : (CommissionSourceTypeEnum::tryFrom((string) $row->source_type)?->label() ?? $row->source_type),
                'earnedAt' => $row->earned_at ? Carbon::parse($row->earned_at)->toIso8601String() : null,
                'paidAt' => $row->paid_at ? Carbon::parse($row->paid_at)->toIso8601String() : null,
            ])
            ->values();
    }

    /**
     * Agent line-commissions ("للعمولات") on the invoices each employee raised.
     *
     * This is money owed to the مندوب named on an invoice line, not to the
     * employee, so it is read from service_invoice_lines and never from
     * commission_ledger. It is summed off the lines rather than the
     * service_invoice_agent pivot because the two hold the same total (the pivot
     * aggregates the lines per agent) and the lines also feed the detail rows.
     *
     * Only approved invoices count, matching when the ledger records a
     * commission as earned. The report's paid/pending status filter is a ledger
     * concept and deliberately does not narrow this column.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array{userId: int, userName: string, amount: float}>
     */
    private function lineCommissionByEmployee(array $scope): Collection
    {
        return $this->lineCommissionQuery($scope)
            ->join('users', 'users.id', '=', 'service_invoices.user_id')
            ->groupBy('service_invoices.user_id', 'users.name')
            ->get([
                'service_invoices.user_id as user_id',
                'users.name as user_name',
                DB::raw('COALESCE(SUM(service_invoice_lines.agent_commission_amount), 0) as amount'),
                DB::raw('COALESCE(SUM(service_invoice_lines.materials_total), 0) as materials'),
            ])
            ->map(fn ($row) => [
                'userId' => (int) $row->user_id,
                'userName' => $row->user_name,
                'amount' => (float) $row->amount,
                'materials' => (float) $row->materials,
            ]);
    }

    /**
     * The same agent line-commissions and materials cost, totalled per day.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, array{amount: float, materials: float}> YYYY-MM-DD => totals
     */
    private function lineCommissionByDay(array $scope): array
    {
        return $this->lineCommissionQuery($scope)
            ->groupBy(DB::raw('DATE('.self::APPROVED_AT.')'))
            ->get([
                DB::raw('DATE('.self::APPROVED_AT.') as day'),
                DB::raw('COALESCE(SUM(service_invoice_lines.agent_commission_amount), 0) as amount'),
                DB::raw('COALESCE(SUM(service_invoice_lines.materials_total), 0) as materials'),
            ])
            ->mapWithKeys(fn ($row) => [(string) $row->day => [
                'amount' => (float) $row->amount,
                'materials' => (float) $row->materials,
            ]])
            ->all();
    }

    /**
     * تاسك 133: إيراد الفواتير المعتمدة يومياً — شاملاً الضريبة، في يوم اعتمادها
     * الذي تُؤرَّخ به العمولات الخارجية والخامات أيضاً. يُجمع من رأس الفاتورة لا
     * من البنود، فلا يتكرّر total_amount بعدد بنودها.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, array{count: int, total: float}> YYYY-MM-DD => totals
     */
    private function revenueByDay(array $scope): array
    {
        return $this->approvedInvoiceQuery(DB::table('service_invoices'), $scope)
            ->groupBy(DB::raw('DATE('.self::APPROVED_AT.')'))
            ->get([
                DB::raw('DATE('.self::APPROVED_AT.') as day'),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('COALESCE(SUM(service_invoices.total_amount), 0) as total'),
            ])
            ->mapWithKeys(fn ($row) => [(string) $row->day => [
                'count' => (int) $row->invoice_count,
                'total' => (float) $row->total,
            ]])
            ->all();
    }

    /**
     * «المحصّل» وضريبته يومياً، كعمودَي التقرير اليومي.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, array{amount: float, vat: float}> YYYY-MM-DD => totals
     */
    private function collectedByDay(array $scope): array
    {
        return $this->collectedQuery($scope)
            ->groupBy(DB::raw('DATE(c.collected_at)'))
            ->get([
                DB::raw('DATE(c.collected_at) as day'),
                DB::raw('COALESCE(SUM(c.amount), 0) as amount'),
                DB::raw('COALESCE(SUM(c.vat), 0) as vat'),
            ])
            ->mapWithKeys(fn ($row) => [(string) $row->day => [
                'amount' => round((float) $row->amount, 2),
                'vat' => round((float) $row->vat, 2),
            ]])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, float> YYYY-MM-DD => refunded
     */
    private function refundsByDay(array $scope): array
    {
        return $this->refundsQuery($scope)
            ->groupBy(DB::raw('DATE(r.created_at)'))
            ->get([DB::raw('DATE(r.created_at) as day'), DB::raw('COALESCE(SUM(r.amount), 0) as amount')])
            ->mapWithKeys(fn ($row) => [(string) $row->day => (float) $row->amount])
            ->all();
    }

    /**
     * One sum per employee (the invoice's raiser), keyed by user id.
     *
     * @return Collection<int, array{userId: int, userName: string, amount: float}>
     */
    private function sumByEmployee(Builder $query, string $userColumn, string $amountColumn): Collection
    {
        return $query
            ->join('users', 'users.id', '=', $userColumn)
            ->groupBy($userColumn, 'users.name')
            ->get([
                $userColumn.' as user_id',
                'users.name as user_name',
                DB::raw("COALESCE(SUM({$amountColumn}), 0) as amount"),
            ])
            ->map(fn ($row) => [
                'userId' => (int) $row->user_id,
                'userName' => $row->user_name,
                'amount' => round((float) $row->amount, 2),
            ])
            ->keyBy('userId');
    }

    /**
     * ما حُصِّل فعلاً، على منوال DailyReportController::collectedDaily(): كل دفعة
     * بيوم قبضها، والفاتورة المسدَّدة عند البيع بلا صفوف دفعات بكامل إجماليها يوم
     * سدادها. ولأن الفرع الثاني يسقط بأول صفّ دفعة، لا تُحسب دفعةٌ مرتين حين
     * يكتمل السداد. المرتجعة بالكامل خارجه كما هي خارج «الإجمالي».
     *
     * @param  array<string, mixed>  $scope
     */
    private function collectedQuery(array $scope): Builder
    {
        $payments = DB::table('invoice_payments as p')
            ->join('service_invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('p.invoice_type', ServiceInvoice::class)
            ->whereIn('i.status', InvoiceStatusEnum::approved())
            ->whereNull('i.deleted_at')
            ->select([
                'i.user_id',
                'i.branch_id',
                DB::raw('p.paid_at as collected_at'),
                DB::raw('p.amount as amount'),
                // تاسك 139: ضريبة الدفعة بنسبة فاتورتها. الضرب في 1.0 يمنع قسمة SQLite الصحيحة.
                DB::raw('p.amount * i.vat_amount * 1.0 / NULLIF(i.total_amount, 0) as vat'),
            ]);

        $settledAtTill = DB::table('service_invoices as i')
            ->where('i.status', InvoiceStatusEnum::PAID->value)
            ->whereNull('i.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('invoice_payments as p')
                ->where('p.invoice_type', ServiceInvoice::class)
                ->whereColumn('p.invoice_id', 'i.id'))
            ->select([
                'i.user_id',
                'i.branch_id',
                DB::raw('i.paid_at as collected_at'),
                DB::raw('i.total_amount as amount'),
                DB::raw('i.vat_amount as vat'),
            ]);

        return DB::query()
            ->fromSub($payments->unionAll($settledAtTill), 'c')
            ->whereNotNull('c.collected_at')
            ->when($scope['branchId'], fn ($q) => $q->where('c.branch_id', $scope['branchId']))
            ->when($scope['userId'], fn ($q) => $q->where('c.user_id', $scope['userId']))
            ->when($scope['from'], fn ($q) => $q->where('c.collected_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('c.collected_at', '<=', $scope['to']));
    }

    /**
     * المرتجعات الجزئية، مؤرَّخةً بيوم تسجيلها، تُطرح من «الإجمالي». الفاتورة
     * المرتجعة بالكامل خارج «الإجمالي» أصلاً (حالتها returned)، فلا يُطرح
     * مرتجعها مرّةً ثانية.
     *
     * @param  array<string, mixed>  $scope
     */
    private function refundsQuery(array $scope): Builder
    {
        return DB::table('refunds as r')
            ->join('service_invoices', 'service_invoices.id', '=', 'r.invoice_id')
            ->where('r.invoice_type', ServiceInvoice::class)
            ->whereNull('r.deleted_at')
            ->whereNull('service_invoices.deleted_at')
            ->whereIn('service_invoices.status', InvoiceStatusEnum::approved())
            ->when($scope['branchId'], fn ($q) => $q->where('service_invoices.branch_id', $scope['branchId']))
            ->when($scope['userId'], fn ($q) => $q->where('service_invoices.user_id', $scope['userId']))
            ->when($scope['from'], fn ($q) => $q->where('r.created_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('r.created_at', '<=', $scope['to']));
    }

    /**
     * Shared base for both line-commission aggregates.
     *
     * @param  array<string, mixed>  $scope
     */
    private function lineCommissionQuery(array $scope): Builder
    {
        return $this->approvedInvoiceQuery(
            DB::table('service_invoice_lines')
                ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_lines.invoice_id'),
            $scope,
        );
    }

    /**
     * يوم الاعتماد = يوم أول دفعة، وإلا paid_at للمسدَّدة عند البيع — كما في
     * DailyReportController::invoiceDaily(). **أول** دفعة لا آخرها: فاتورة العربون
     * تدخل «الإجمالي» يوم عربونها بكامل قيمتها، ولا تقفز يوم اكتمال سدادها.
     */
    private const APPROVED_AT = 'COALESCE(fp.first_paid_at, service_invoices.paid_at)';

    /**
     * The approved invoices (paid + partially paid) in scope, by approval day —
     * one filter set for revenue and the line columns, so they always cover the
     * same invoices.
     *
     * @param  array<string, mixed>  $scope
     */
    private function approvedInvoiceQuery(Builder $query, array $scope): Builder
    {
        $firstPayment = DB::table('invoice_payments')
            ->where('invoice_type', ServiceInvoice::class)
            ->groupBy('invoice_id')
            ->select('invoice_id', DB::raw('MIN(paid_at) as first_paid_at'));

        return $query
            ->leftJoinSub($firstPayment, 'fp', 'fp.invoice_id', '=', 'service_invoices.id')
            ->whereIn('service_invoices.status', InvoiceStatusEnum::approved())
            // DB::table() bypasses the model's soft-delete scope.
            ->whereNull('service_invoices.deleted_at')
            ->whereRaw(self::APPROVED_AT.' is not null')
            ->when($scope['branchId'], fn ($q) => $q->where('service_invoices.branch_id', $scope['branchId']))
            ->when($scope['userId'], fn ($q) => $q->where('service_invoices.user_id', $scope['userId']))
            ->when($scope['from'], fn ($q) => $q->whereRaw(self::APPROVED_AT.' >= ?', [$scope['from']]))
            ->when($scope['to'], fn ($q) => $q->whereRaw(self::APPROVED_AT.' <= ?', [$scope['to']]));
    }

    /**
     * Employees selectable in the filter, optionally scoped to a branch. Only
     * those that actually carry ledger entries are listed.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function employeeOptions(?int $branchId): Collection
    {
        return User::query()
            ->whereIn('id', function ($q) use ($branchId) {
                $q->select('user_id')
                    ->from('commission_ledger')
                    ->when($branchId, fn ($sub) => $sub->where('branch_id', $branchId));
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }
}
