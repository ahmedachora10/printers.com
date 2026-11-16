<?php

namespace App\Http\Controllers;

use App\Actions\ProductCategory\CreateProductCategoryAction;
use App\Actions\ProductCategory\DeleteProductCategoryAction;
use App\Actions\ProductCategory\UpdateProductCategoryAction;
use App\Exports\ImportTemplateExport;
use App\Exports\ProductCategoriesExport;
use App\Http\Controllers\Concerns\RunsExcelImports;
use App\Http\Requests\ProductCategory\StoreProductCategoryRequest;
use App\Http\Requests\ProductCategory\UpdateProductCategoryRequest;
use App\Http\Resources\ProductCategory\ProductCategoryResource;
use App\Imports\ProductCategoriesImport;
use App\Models\Branch;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductCategoryController extends Controller
{
    use RunsExcelImports;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProductCategory::class);

        $isSuper = $request->user()->roleName->isSuperAdmin();

        // تاسك 237: كل فرع يرى العامة النشطة + ما يشمله (ومعطّلات فرعه — تاسك 240).
        // السوبر أدمن يرى الكل ويضيّق بالفرع («global» = العامة وحدها).
        $items = ProductCategory::query()
            ->with('branches:id,name')
            ->when(! $isSuper, fn ($q) => $q->managedListFor((int) $request->user()->branchId))
            ->when($isSuper && $request->filled('branch_id'), fn ($q) => $request->input('branch_id') === 'global'
                ? $q->whereDoesntHave('branches')
                : $q->whereHas('branches', fn ($b) => $b->whereKey((int) $request->input('branch_id'))))
            ->filteredBy($request->only(['search', 'status']))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('product-categories/index', [
            'items' => ProductCategoryResource::collection($items),
            'branches' => $isSuper ? Branch::query()->active()->orderBy('name')->get(['id', 'name']) : null,
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'branch_id' => $isSuper ? $request->input('branch_id') : null,
            ],
        ]);
    }

    public function store(StoreProductCategoryRequest $request, CreateProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', ProductCategory::class);

        $action->handle($request->validated());

        return back(fallback: route('product-categories.index'))->with('success', 'تم إنشاء فئة المنتج بنجاح');
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory, UpdateProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $productCategory);

        $action->handle($productCategory, $request->validated());

        return back(fallback: route('product-categories.index'))->with('success', 'تم تحديث فئة المنتج بنجاح');
    }

    public function destroy(ProductCategory $productCategory, DeleteProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('delete', $productCategory);

        $action->handle($productCategory);

        return back(fallback: route('product-categories.index'))->with('success', 'تم حذف فئة المنتج بنجاح');
    }

    public function toggleStatus(ProductCategory $productCategory, UpdateProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $productCategory);

        $action->handle($productCategory, ['is_active' => ! $productCategory->is_active]);

        return back(fallback: route('product-categories.index'))->with('success', 'تم تحديث حالة فئة المنتج بنجاح');
    }

    /**
     * تاسك 72: فئات المنتجات كورقة Excel — ما يراه المستخدم: كل الفئات للسوبر أدمن،
     * والعامة + فئات فرعه لمدير الفرع (تاسك 237).
     */
    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('viewAny', ProductCategory::class);

        return Excel::download(
            new ProductCategoriesExport($request->only(['search', 'status']), $this->branchScope($request)),
            'product-categories-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /** ما الذي سيفعله استيراد هذه الورقة — يُقرأ ويُعرض ثم يُلغى قبل أن يُكتب. */
    public function importPreview(Request $request): JsonResponse
    {
        Gate::authorize('create', ProductCategory::class);

        return $this->previewImport($request, fn (bool $dryRun) => new ProductCategoriesImport($dryRun, $this->branchScope($request)));
    }

    /** استيراد ورقة الفئات: إضافة وتحديث بمطابقة الاسم، بلا حذف. */
    public function import(Request $request): JsonResponse
    {
        Gate::authorize('create', ProductCategory::class);

        return $this->commitImport($request, fn (bool $dryRun) => new ProductCategoriesImport($dryRun, $this->branchScope($request)));
    }

    /** ورقة فارغة بعناوين هذا الاستيراد وصفٌّ مثال. */
    public function importTemplate(): BinaryFileResponse
    {
        Gate::authorize('create', ProductCategory::class);

        return Excel::download(
            new ImportTemplateExport((new ProductCategoriesExport)->headings(), [
                ['قرطاسية', 1],
            ]),
            'product-categories-template.xlsx',
        );
    }

    /** فرع مدير الفرع، أو null للسوبر أدمن (كل الفئات، والجديدة عامة). */
    private function branchScope(Request $request): ?int
    {
        return $request->user()->roleName->isSuperAdmin() ? null : $request->user()->branchId;
    }
}
