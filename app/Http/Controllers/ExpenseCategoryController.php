<?php

namespace App\Http\Controllers;

use App\Actions\ExpenseCategory\CreateExpenseCategoryAction;
use App\Actions\ExpenseCategory\DeleteExpenseCategoryAction;
use App\Actions\ExpenseCategory\UpdateExpenseCategoryAction;
use App\Http\Requests\ExpenseCategory\StoreExpenseCategoryRequest;
use App\Http\Requests\ExpenseCategory\UpdateExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategory\ExpenseCategoryResource;
use App\Models\Branch;
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

        $isSuper = $request->user()->roleName->isSuperAdmin();

        // تاسك 102/238: كل فرع يرى العامة + ما يشمله. السوبر أدمن يرى الكل، ويضيّق
        // بالفرع («global» = العامة وحدها).
        // تاسك 240: ولا يرى مدير الفرع المعطّلة إلا ما يملكه — لا يملك تفعيل غيرها.
        $items = ExpenseCategory::query()
            ->with('branches:id,name')
            ->when(! $isSuper, fn ($q) => $q->managedListFor((int) $request->user()->branchId))
            ->when($isSuper && $request->filled('branch_id'), fn ($q) => $request->input('branch_id') === 'global'
                ? $q->whereDoesntHave('branches')
                : $q->whereHas('branches', fn ($b) => $b->whereKey((int) $request->input('branch_id'))))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('expense-categories/index', [
            'items' => ExpenseCategoryResource::collection($items),
            'branches' => $isSuper ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : null,
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'branch_id' => $isSuper ? $request->input('branch_id') : null,
            ],
        ]);
    }

    public function store(StoreExpenseCategoryRequest $request, CreateExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', ExpenseCategory::class);

        $action->handle($request->validated());

        return back(fallback: route('expense-categories.index'))->with('success', 'تم إنشاء فئة المصروف بنجاح');
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory, UpdateExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $expenseCategory);

        $action->handle($expenseCategory, $request->validated());

        return back(fallback: route('expense-categories.index'))->with('success', 'تم تحديث فئة المصروف بنجاح');
    }

    public function destroy(ExpenseCategory $expenseCategory, DeleteExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('delete', $expenseCategory);

        $action->handle($expenseCategory);

        return back(fallback: route('expense-categories.index'))->with('success', 'تم حذف فئة المصروف بنجاح');
    }

    public function toggleStatus(ExpenseCategory $expenseCategory, UpdateExpenseCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $expenseCategory);

        $action->handle($expenseCategory, ['is_active' => ! $expenseCategory->is_active]);

        return back(fallback: route('expense-categories.index'))->with('success', 'تم تحديث حالة فئة المصروف بنجاح');
    }
}
