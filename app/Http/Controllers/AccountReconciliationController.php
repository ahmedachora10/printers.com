<?php

namespace App\Http\Controllers;

use App\Actions\AccountReconciliation\ApproveAccountReconciliationAction;
use App\Actions\AccountReconciliation\BuildReconciliationFiguresAction;
use App\Actions\AccountReconciliation\SaveAccountReconciliationAction;
use App\Http\Controllers\Concerns\BuildsPagedProps;
use App\Http\Requests\AccountReconciliation\StoreAccountReconciliationRequest;
use App\Models\AccountReconciliation;
use App\Models\Branch;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تاسك 121 — مطابقة الحسابات: صافي النظام مقابل الأجهزة المُدخلة + الطرق المسحوبة تلقائياً.
 * سجلٌّ لكل (فرع + يوم)، والجدول بهيكله من التاسك 122.
 */
class AccountReconciliationController extends Controller
{
    use BuildsPagedProps;

    public function index(Request $request, BuildReconciliationFiguresAction $figures): Response
    {
        Gate::authorize('viewAny', AccountReconciliation::class);

        $user = $request->user();
        $isSuper = $user->roleName->isSuperAdmin();
        $branches = $isSuper ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect();
        $branchId = $isSuper ? ($request->integer('branch') ?: $branches->first()?->id) : $user->branchId;
        abort_unless($branchId, 403);

        $date = $request->date('date')?->toDateString() ?? today()->toDateString();

        $reconciliation = AccountReconciliation::query()
            ->with(['devices', 'creator:id,name', 'approvedBy:id,name', 'media'])
            ->where('branch_id', $branchId)
            ->where('date', $date)
            ->first();

        $live = $reconciliation?->isApproved() ? null : $figures->handle($branchId, $date);
        $systemNet = $live ? $live['systemNet'] : (float) $reconciliation->system_net;
        $autoTotal = $live ? $live['autoTotal'] : (float) $reconciliation->auto_total;

        $history = AccountReconciliation::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('finance/reconciliation/index', [
            'filters' => ['branch' => $isSuper ? $branchId : null, 'date' => $date],
            'branches' => $branches,
            'networkMethods' => PaymentMethod::query()
                ->visibleToBranch($branchId)
                ->isNetwork()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'figures' => [
                'systemNet' => $systemNet,
                'autoTotal' => $autoTotal,
                'autoBreakdown' => $live ? $live['autoBreakdown'] : ($reconciliation->auto_breakdown ?? []),
                'frozen' => $live === null,
            ],
            'reconciliation' => $reconciliation ? [
                'id' => $reconciliation->id,
                'devices' => $reconciliation->devices->map(fn ($d) => [
                    'paymentMethodId' => $d->payment_method_id,
                    'deviceLabel' => $d->device_label,
                    'amount' => (float) $d->amount,
                ]),
                'notes' => $reconciliation->notes,
                'createdBy' => $reconciliation->creator?->name,
                'approvedBy' => $reconciliation->approvedBy?->name,
                'approvedAt' => $reconciliation->approved_at?->toIso8601String(),
                'canApprove' => $user->can('approve', $reconciliation),
                'canUnapprove' => $user->can('unapprove', $reconciliation),
                'settlementFileUrl' => $reconciliation->settlementFile()
                    ? route('reports.sales.settlement-file.show', $reconciliation)
                    : null,
            ] : null,
            // المعتمَد وحده له فرقٌ مجمَّد؛ غيره يُفتح بتاريخه ليُحسب حيّاً.
            'history' => $this->pagedProp($history, fn (AccountReconciliation $r) => [
                'date' => $r->date,
                'devicesTotal' => (float) $r->devices_total,
                'difference' => $r->isApproved()
                    ? round((float) $r->devices_total + (float) $r->auto_total - (float) $r->system_net, 2)
                    : null,
                'approved' => $r->isApproved(),
            ]),
        ]);
    }

    public function store(StoreAccountReconciliationRequest $request, SaveAccountReconciliationAction $action): RedirectResponse
    {
        $branchId = $request->branchId();
        abort_unless($branchId, 403);

        $action->handle(
            $branchId,
            $request->validated('date'),
            $request->validated('devices'),
            $request->validated('notes'),
            $request->user(),
        );

        return back()->with('success', 'تم حفظ المطابقة');
    }

    public function approve(AccountReconciliation $reconciliation, ApproveAccountReconciliationAction $action): RedirectResponse
    {
        Gate::authorize('approve', $reconciliation);
        $action->handle($reconciliation, request()->user());

        return back()->with('success', 'تم اعتماد المطابقة');
    }

    public function unapprove(AccountReconciliation $reconciliation, ApproveAccountReconciliationAction $action): RedirectResponse
    {
        Gate::authorize('unapprove', $reconciliation);
        $action->revert($reconciliation);

        return back()->with('success', 'تم إلغاء اعتماد المطابقة');
    }
}
