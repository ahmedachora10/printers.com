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
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Expense::class);

        $branchId = auth()->user()->branchId ?? null;

        $base = Expense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('supplier_name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('receipt_reference', 'like', '%'.$request->input('search').'%');
            }))
            ->when($request->filled('expense_category_id'), fn ($q) => $q->where('expense_category_id', (int) $request->input('expense_category_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->input('to')));

        $items = (clone $base)
            ->with(['category', 'user'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $periodTotal = (float) (clone $base)->sum('total');

        $categories = ExpenseCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

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
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
        ]);
    }

    public function store(StoreExpenseRequest $request, CreateExpenseAction $action): RedirectResponse
    {
        Gate::authorize('create', Expense::class);

        $action->handle($request->validated());

        return to_route('expenses.index')->with('success', 'تم تسجيل المصروف بنجاح');
    }

    public function update(UpdateExpenseRequest $request, Expense $expense, UpdateExpenseAction $action): RedirectResponse
    {
        Gate::authorize('update', $expense);

        $action->handle($expense, $request->validated());

        return to_route('expenses.index')->with('success', 'تم تحديث المصروف بنجاح');
    }

    public function destroy(Expense $expense, DeleteExpenseAction $action): RedirectResponse
    {
        Gate::authorize('delete', $expense);

        $action->handle($expense);

        return to_route('expenses.index')->with('success', 'تم حذف المصروف بنجاح');
    }
}
