<?php

namespace App\Http\Controllers;

use App\Actions\ExpenseCategory\CreateExpenseCategoryAction;
use App\Actions\ExpenseCategory\DeleteExpenseCategoryAction;
use App\Actions\ExpenseCategory\UpdateExpenseCategoryAction;
use App\Http\Requests\ExpenseCategory\StoreExpenseCategoryRequest;
use App\Http\Requests\ExpenseCategory\UpdateExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategory\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ExpenseCategory::class);

        $items = ExpenseCategory::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('expense-categories/index', [
            'items' => ExpenseCategoryResource::collection($items),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreExpenseCategoryRequest $request, CreateExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', ExpenseCategory::class);

        $action->handle($request->validated());

        return to_route('expense-categories.index')->with('success', 'تم إنشاء فئة المصروف بنجاح');
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory, UpdateExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $expenseCategory);

        $action->handle($expenseCategory, $request->validated());

        return to_route('expense-categories.index')->with('success', 'تم تحديث فئة المصروف بنجاح');
    }

    public function destroy(ExpenseCategory $expenseCategory, DeleteExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('delete', $expenseCategory);

        $action->handle($expenseCategory);

        return to_route('expense-categories.index')->with('success', 'تم حذف فئة المصروف بنجاح');
    }

    public function toggleStatus(ExpenseCategory $expenseCategory, UpdateExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $expenseCategory);

        $action->handle($expenseCategory, ['is_active' => ! $expenseCategory->is_active]);

        return to_route('expense-categories.index')->with('success', 'تم تحديث حالة فئة المصروف بنجاح');
    }
}
