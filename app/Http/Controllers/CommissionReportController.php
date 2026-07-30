<?php

namespace App\Http\Controllers;

use App\Actions\Report\BuildReportDayRange;
use App\Enums\CommissionSourceTypeEnum;
use App\Enums\Roles;
use App\Exports\CommissionReportExport;
use App\Http\Requests\Report\CommissionReportFilterRequest;
use App\Models\Branch;
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

        return Inertia::render('reports/commissions/index', [
            'summary' => $summary,
            'byDay' => $this->dailyRows($scope),
            'lines' => $lines,
            'totals' => [
                'earned' => (float) $summary->sum('earned'),
                'paid' => (float) $summary->sum('paid'),
                'pending' => (float) $summary->sum('pending'),
                'tahazir' => (float) $summary->sum('tahazir'),
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
     * and accountants to their own branch. Only super-admin may pick freely.
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
            ->when($scope['status'] === 'pending', fn ($q) => $q->whereNull('commission_ledger.paid_at'));
    }

    /**
     * Per-employee aggregate rows.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function summaryRows(array $scope): Collection
    {
        return $this->baseQuery($scope)
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
        $days = [];

        foreach ($this->dayRange->handle($scope) as $day) {
            $days[$day] = ['date' => $day, 'lineCount' => 0, 'earned' => 0.0, 'paid' => 0.0, 'pending' => 0.0, 'tahazir' => 0.0];
        }

        $rows = $this->baseQuery($scope)
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

        ksort($days);

        return collect(array_values($days));
    }

    /**
     * Line-level detail: one row per ledger entry, resolved to its service
     * invoice for drill-down (invoice number, service name, date).
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function detailLines(array $scope): Collection
    {
        return $this->baseQuery($scope)
            ->where('commission_ledger.invoice_line_type', ServiceInvoiceLine::class)
            ->join('users', 'users.id', '=', 'commission_ledger.user_id')
            ->join('service_invoice_lines', 'service_invoice_lines.id', '=', 'commission_ledger.invoice_line_id')
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_lines.invoice_id')
            ->orderByDesc('commission_ledger.earned_at')
            ->orderByDesc('commission_ledger.id')
            ->get([
                'commission_ledger.id as id',
                'commission_ledger.user_id as user_id',
                'users.name as user_name',
                'service_invoices.invoice_number as invoice_number',
                'service_invoices.status as invoice_status',
                'service_invoice_lines.service_name as service_name',
                'commission_ledger.amount as amount',
                'commission_ledger.is_tahazir as is_tahazir',
                'commission_ledger.tier_applied as tier_applied',
                'commission_ledger.source_type as source_type',
                'commission_ledger.earned_at as earned_at',
                'commission_ledger.paid_at as paid_at',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'userId' => (int) $row->user_id,
                'userName' => $row->user_name,
                'invoiceNumber' => $row->invoice_number,
                'invoiceStatus' => $row->invoice_status,
                'serviceName' => $row->service_name,
                'amount' => (float) $row->amount,
                'isTahazir' => (bool) $row->is_tahazir,
                'tierApplied' => $row->tier_applied !== null ? (int) $row->tier_applied : null,
                'sourceType' => $row->source_type,
                'sourceLabel' => CommissionSourceTypeEnum::tryFrom((string) $row->source_type)?->label() ?? $row->source_type,
                'earnedAt' => $row->earned_at ? Carbon::parse($row->earned_at)->toIso8601String() : null,
                'paidAt' => $row->paid_at ? Carbon::parse($row->paid_at)->toIso8601String() : null,
            ])
            ->values();
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
