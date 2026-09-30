<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Exports\NetworkReportExport;
use App\Models\Branch;
use App\Models\CardType;
use App\Models\NetworkDevice;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تاسك 146 — تقرير مبالغ الشبكة: صفوف موازنات الأجهزة في مطابقة الحسابات،
 * مؤرَّخةً بيوم المطابقة، مصفّاة بالجهاز ونوع البطاقة. المعتمد وغيره معاً (كتقرير
 * المصروفات)، وعمود الحالة يميّزهما. صفوف ما قبل التاسك (بلا جهاز) خارجه.
 */
class NetworkReportController extends Controller
{
    public function index(Request $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $this->scope($request, $resolveScope);
        $rows = $this->rows($scope);

        return Inertia::render('reports/network/index', [
            'rows' => $rows,
            'total' => round((float) $rows->sum('amount'), 2),
            'byCardType' => $this->groupTotals($rows, fn (array $r) => $r['cardTypeName']),
            'byDevice' => $this->groupTotals($rows, fn (array $r) => "{$r['deviceName']} — {$r['deviceNumber']}"),
            'filters' => [
                'from' => $scope['from']->toDateString(),
                'to' => $scope['to']->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'device' => $scope['deviceId'] ? (string) $scope['deviceId'] : null,
                'cardType' => $scope['cardTypeId'] ? (string) $scope['cardTypeId'] : null,
            ],
            'defaultDate' => Carbon::today()->toDateString(),
            'branches' => $scope['isSuper'] ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'devices' => NetworkDevice::withTrashed()
                ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
                ->orderBy('name')
                ->get(['id', 'name', 'number'])
                ->map(fn (NetworkDevice $d) => ['id' => $d->id, 'name' => "{$d->name} — {$d->number}"]),
            'cardTypes' => CardType::withTrashed()->orderBy('id')->get(['id', 'name']),
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(Request $request, ResolveReportScope $resolveScope): BinaryFileResponse
    {
        return Excel::download(
            new NetworkReportExport($this->rows($this->scope($request, $resolveScope))),
            'network-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /** @return array{isSuper: bool, branchId: ?int, from: Carbon, to: Carbon, deviceId: ?int, cardTypeId: ?int} */
    private function scope(Request $request, ResolveReportScope $resolveScope): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'device' => ['nullable', 'integer'],
            'card_type' => ['nullable', 'integer'],
        ]);

        return [
            ...$resolveScope->handle($request),
            'deviceId' => $request->integer('device') ?: null,
            'cardTypeId' => $request->integer('card_type') ?: null,
        ];
    }

    /**
     * صفٌّ لكل (مطابقة × جهاز × نوع بطاقة)، الأحدث أولاً. يغذّي الجدول والتصدير معاً.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(array $scope): Collection
    {
        return DB::table('account_reconciliation_devices as d')
            ->join('account_reconciliations as r', 'r.id', '=', 'd.account_reconciliation_id')
            ->join('network_devices as n', 'n.id', '=', 'd.network_device_id')
            ->join('card_types as c', 'c.id', '=', 'd.card_type_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            // DB::table يتخطّى الحذف الناعم — المطابقة المحذوفة تُستبعد صراحةً.
            ->whereNull('r.deleted_at')
            ->when($scope['branchId'], fn (Builder $q) => $q->where('r.branch_id', $scope['branchId']))
            ->when($scope['deviceId'], fn (Builder $q) => $q->where('d.network_device_id', $scope['deviceId']))
            ->when($scope['cardTypeId'], fn (Builder $q) => $q->where('d.card_type_id', $scope['cardTypeId']))
            ->whereBetween('r.date', [$scope['from']->toDateString(), $scope['to']->toDateString()])
            ->orderByDesc('r.date')
            ->orderBy('n.name')
            ->orderBy('c.id')
            ->get(['d.id', 'r.date', 'b.name as branch_name', 'n.name as device_name', 'd.device_label',
                'c.name as card_type_name', 'd.amount', 'r.approved_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'date' => Carbon::parse($row->date)->toDateString(),
                'branchName' => $row->branch_name,
                'deviceName' => $row->device_name,
                'deviceNumber' => $row->device_label,
                'cardTypeName' => $row->card_type_name,
                'amount' => (float) $row->amount,
                'approved' => $row->approved_at !== null,
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{name: string, count: int, total: float}>
     */
    private function groupTotals(Collection $rows, callable $key): array
    {
        return $rows->groupBy($key)
            ->map(fn (Collection $g, string $name) => ['name' => $name, 'count' => $g->count(), 'total' => round((float) $g->sum('amount'), 2)])
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
