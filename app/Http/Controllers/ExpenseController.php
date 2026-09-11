<?php

namespace App\Http\Controllers;

use App\Actions\Expense\CreateExpenseAction;
use App\Actions\Expense\DeleteExpenseAction;
use App\Actions\Expense\UpdateExpenseAction;
use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Http\Resources\Expense\ExpenseResource;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Expense::class);

        $branchId = auth()->user()->branchId ?? null;
        [$from, $to] = $this->dateRange($request);

        $base = Expense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('supplier_name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('receipt_reference', 'like', '%'.$request->input('search').'%');
            }))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', (int) $request->input('expense_category_id')))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to));

        $items = (clone $base)
            ->with(['category', 'user'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $periodTotal = (float) (clone $base)->sum('total');

        $categories = ExpenseCategory::activeOptionsFor($branchId);

        return Inertia::render('expenses/index', [
            'items' => ExpenseResource::collection($items),
            'periodTotal' => $periodTotal,
            'categories' => $categories,
            'branches' => auth()->user()->roleName?->isSuperAdmin()
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
            'filters' => [
                'search' => $request->input('search'),
                'expense_category_id' => $request->input('expense_category_id'),
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
