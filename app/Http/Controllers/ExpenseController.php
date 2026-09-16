<?php

namespace App\Http\Controllers;

use App\Actions\Expense\ApproveExpensesAction;
use App\Actions\Expense\CreateExpenseAction;
use App\Actions\Expense\DeleteExpenseAction;
use App\Actions\Expense\UpdateExpenseAction;
use App\Enums\InvoiceStatusEnum;
use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Http\Resources\Expense\ExpenseResource;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ServiceInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Expense::class);

        $branchId = auth()->user()->branchId ?? null;
        [$from, $to] = $this->dateRange($request);
        $base = $this->filteredQuery($request);

        $items = (clone $base)
            ->with(['category', 'user', 'media', 'invoice:id,invoice_number', 'approvedBy:id,name', 'activities.causer:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $periodTotal = (float) (clone $base)->sum('total');
        $pending = (clone $base)->whereNull('approved_at');

        $categories = ExpenseCategory::activeOptionsFor($branchId);

        return Inertia::render('expenses/index', [
            'items' => ExpenseResource::collection($items),
            'periodTotal' => $periodTotal,
            // تاسك 113: لنافذة تأكيد «اعتماد جميع المصروفات».
            'pendingSummary' => ['count' => (clone $pending)->count(), 'total' => (float) $pending->sum('total')],
            'canApproveAll' => Gate::allows('approveAny', Expense::class),
            'categories' => $categories,
            'branches' => auth()->user()->roleName?->isSuperAdmin()
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
            'filters' => [
                'search' => $request->input('search'),
                'expense_category_id' => $request->input('expense_category_id'),
                'approval' => $request->input('approval'),
                // المدى المطبَّق فعلاً لا المُرسَل — فيُضيء زرّ «اليوم» حين
                // تُفتح الشاشة بلا مدى.
                'from' => $from,
                'to' => $to,
                'range' => $from === null && $to === null ? 'all' : null,
            ],
            'defaultDate' => Carbon::today()->toDateString(),
        ]);
    }

    /**
     * فلاتر الشاشة كلّها في استعلامٍ واحد — يقرؤه الجدول و«اعتماد جميع المصروفات»
     * (تاسك 113)، فلا يعتمد الزرّ غير ما يراه المستخدم.
     *
     * @return Builder<Expense>
     */
    private function filteredQuery(Request $request): Builder
    {
        $branchId = auth()->user()->branchId ?? null;
        [$from, $to] = $this->dateRange($request);

        return Expense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('supplier_name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('receipt_reference', 'like', '%'.$request->input('search').'%');
            }))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', (int) $request->input('expense_category_id')))
            ->when($request->input('approval') === 'approved', fn ($q) => $q->whereNotNull('approved_at'))
            ->when($request->input('approval') === 'pending', fn ($q) => $q->whereNull('approved_at'))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to));
    }

    /** تاسك 113 — اعتماد مصروفٍ واحد. */
    public function approve(Expense $expense, ApproveExpensesAction $action): RedirectResponse
    {
        Gate::authorize('approve', $expense);

        $action->handle(Expense::whereKey($expense->id));

        return back()->with('success', 'تم اعتماد المصروف');
    }

    /** تاسك 113 — اعتماد كل غير المعتمد تحت الفلاتر الحالية (لا معرّفات الصفحة المعروضة). */
    public function approveAll(Request $request, ApproveExpensesAction $action): RedirectResponse
    {
        Gate::authorize('approveAny', Expense::class);

        $count = $action->handle($this->filteredQuery($request));

        return back()->with('success', "تم اعتماد {$count} مصروف");
    }

    /**
     * تاسك 104 — الشاشة تفتح على مصروفات اليوم. `range=all` («كل الفترات»)
     * يرفع المدى، وإلا فكل طرفٍ غائب = اليوم — كتقرير المبيعات (ResolveReportScope).
     * والطرف الغائب ليس نادراً: شريط التاريخ يُسقط ما ساوى اليوم، فـ«آخر 7 أيام»
     * تصل `from` وحدها.
     *
     * «الكل» قيمةٌ صريحة لا فراغ: الفراغ صار يعني «اليوم»، ولو بقي يعني «الكل»
     * لأعاد زرُّ «كل الفترات» المستخدمَ إلى اليوم. ومدىً صريح يغلبها.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateRange(Request $request): array
    {
        $explicit = $request->filled('from') || $request->filled('to');

        if (! $explicit && $request->input('range') === 'all') {
            return [null, null];
        }

        $today = Carbon::today()->toDateString();

        return [$request->input('from') ?: $today, $request->input('to') ?: $today];
    }

    /** تاسك 112 — مرفق المصروف من القرص الخاص، مفوَّضاً بصلاحية العرض (فرع المصروف). */
    public function attachment(Expense $expense, Request $request): FileResponse
    {
        Gate::authorize('view', $expense);

        $media = $expense->attachment();
        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }

    /**
     * تاسك 112 — بحث «ربط بفاتورة/طلب»: فواتير خدمات فرع المصروف برقمها أو
     * اسم عميلها. السوبر أدمن يمرّر فرع النافذة، وغيره مقيَّدٌ بفرعه.
     */
    public function invoiceOptions(Request $request): JsonResponse
    {
        Gate::authorize('create', Expense::class);

        $user = $request->user();
        $branchId = $user->roleName->isSuperAdmin() ? $request->integer('branch_id') : $user->branchId;
        $q = (string) $request->query('q', '');

        $invoices = ServiceInvoice::query()
            ->with('customer:id,full_name')
            ->where('branch_id', $branchId)
            ->where('status', '!=', InvoiceStatusEnum::CANCELLED)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('invoice_number', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$q}%"))))
            ->latest('id')
            ->limit(20)
            ->get(['id', 'invoice_number', 'customer_id', 'created_at']);

        return response()->json(['data' => $invoices->map(fn (ServiceInvoice $invoice) => [
            'id' => $invoice->id,
            'invoiceNumber' => $invoice->invoice_number,
            'customerName' => $invoice->customer?->full_name,
            'date' => $invoice->created_at->format('d/m/Y'),
        ])]);
    }

    public function store(StoreExpenseRequest $request, CreateExpenseAction $action): RedirectResponse
    {
        Gate::authorize('create', Expense::class);

        $action->handle($request->validated());

        return back(fallback: route('expenses.index'))->with('success', 'تم تسجيل المصروف بنجاح');
    }

    public function update(UpdateExpenseRequest $request, Expense $expense, UpdateExpenseAction $action): RedirectResponse
    {
        Gate::authorize('update', $expense);

        $action->handle($expense, $request->validated());

        return back(fallback: route('expenses.index'))->with('success', 'تم تحديث المصروف بنجاح');
    }

    public function destroy(Expense $expense, DeleteExpenseAction $action): RedirectResponse
    {
        Gate::authorize('delete', $expense);

        $action->handle($expense);

        return back(fallback: route('expenses.index'))->with('success', 'تم حذف المصروف بنجاح');
    }
}
