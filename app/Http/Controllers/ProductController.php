<?php

namespace App\Http\Controllers;

use App\Actions\Product\CreateProductAction;
use App\Actions\Product\DeleteProductAction;
use App\Actions\Product\UpdateProductAction;
use App\Exports\ImportTemplateExport;
use App\Exports\ProductsExport;
use App\Http\Controllers\Concerns\RunsExcelImports;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\Product\ProductResource;
use App\Imports\ProductsImport;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    use RunsExcelImports;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $branchId = $this->viewScope($request);

        $items = Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['category', 'unit'])
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('sku', 'like', '%'.$request->input('search').'%');
            }))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->input('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->orderBy('name')
            ->paginate(12);

        $lowStockCount = Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereColumn('current_stock', '<=', 'min_stock_level')
            ->where('is_active', true)
            ->count();

        $categories = ProductCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $units = ProductUnit::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('inventory/products/index', [
            'items' => ProductResource::collection($items),
            'lowStockCount' => $lowStockCount,
            'categories' => $categories,
            'units' => $units,
            // تاسك 72: نطاق التصدير/الاستيراد. السوبر أدمن وحده يختار الفرع،
            // ومدير الفرع يُقال له اسم فرعه بدل أن يُخيَّر فيما لا خيار فيه.
            'branches' => $this->isSuperAdmin($request)
                ? Branch::query()->active()->orderBy('name')->get(['id', 'name'])
                : null,
            'ownBranchName' => $this->isSuperAdmin($request)
                ? null
                : $request->user()?->workBranch()?->name,
            'filters' => [
                'search' => $request->input('search'),
                'category_id' => $request->input('category_id'),
                'status' => $request->input('status'),
                'branch' => $request->input('branch'),
            ],
        ]);
    }

    public function store(StoreProductRequest $request, CreateProductAction $action): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $action->handle($request->validated());

        return back(fallback: route('inventory.products.index'))->with('success', 'تم إضافة المنتج بنجاح');
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductAction $action): RedirectResponse
    {
        Gate::authorize('update', $product);

        $action->handle($product, $request->validated());

        return back(fallback: route('inventory.products.index'))->with('success', 'تم تحديث المنتج بنجاح');
    }

    public function destroy(Product $product, DeleteProductAction $action): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $action->handle($product);

        return back(fallback: route('inventory.products.index'))->with('success', 'تم حذف المنتج بنجاح');
    }

    public function toggleStatus(Product $product, UpdateProductAction $action): RedirectResponse
    {
        Gate::authorize('update', $product);

        $action->handle($product, ['is_active' => ! $product->is_active]);

        return back(fallback: route('inventory.products.index'))->with('success', 'تم تحديث حالة المنتج بنجاح');
    }

    /**
     * تاسك 72: منتجات النطاق الذي يملكه المستخدم كورقة Excel — فرعه وحده، أو
     * الفرع الذي يرشّحه فلتر السوبر أدمن (وكل الفروع حين لا يرشّح شيئاً).
     */
    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('viewAny', Product::class);

        $branchId = $this->viewScope($request);

        return Excel::download(
            new ProductsExport($branchId),
            'products-'.($branchId ? 'branch-'.$branchId.'-' : '').now()->format('Y-m-d').'.xlsx',
        );
    }

    /** ما الذي سيفعله استيراد هذه الورقة — يُقرأ ويُعرض ثم يُلغى قبل أن يُكتب. */
    public function importPreview(Request $request): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $branchId = $this->importBranchId($request);

        return $this->previewImport($request, fn (bool $dryRun) => new ProductsImport($branchId, $dryRun));
    }

    /** استيراد ورقة المنتجات إلى فرعٍ واحد: إضافة وتحديث بمفتاح (sku, branch_id). */
    public function import(Request $request): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $branchId = $this->importBranchId($request);

        return $this->commitImport($request, fn (bool $dryRun) => new ProductsImport($branchId, $dryRun));
    }

    /** ورقة فارغة بعناوين هذا الاستيراد وصفٌّ مثال. */
    public function importTemplate(): BinaryFileResponse
    {
        Gate::authorize('create', Product::class);

        return Excel::download(
            new ImportTemplateExport((new ProductsExport)->headings(), [
                ['SKU-1-00000001', 'ورق A4', 'قرطاسية', 'قطعة', '12.00', '18.00', '10', '', 'لا', 1, '', ''],
            ]),
            'products-template.xlsx',
        );
    }

    private function isSuperAdmin(Request $request): bool
    {
        return (bool) $request->user()?->roleName?->isSuperAdmin();
    }

    /**
     * الفرع الذي تُقرأ منه الشاشة والتصدير: مدير الفرع مثبَّتٌ على فرعه، والسوبر
     * أدمن يرى الجميع ما لم يرشّح فرعاً بـ`?branch=`.
     */
    private function viewScope(Request $request): ?int
    {
        if (! $this->isSuperAdmin($request)) {
            return $request->user()?->branchId;
        }

        return $request->filled('branch') ? (int) $request->input('branch') : null;
    }

    /**
     * الفرع الذي يهبط عليه الاستيراد. `products.branch_id` إلزامي، وعمود «الفرع»
     * في ملفّ التصدير للقراءة وحده — فالوجهة تأتي من الطلب: فرع مدير الفرع، أو
     * الفرع الذي يسمّيه السوبر أدمن صراحةً في نافذة الاستيراد. ولا استيراد بلا
     * وجهةٍ منطوقة: صفوفٌ تهبط على فرعٍ لم يُذكر أسوأ من رفضٍ مفهوم.
     */
    private function importBranchId(Request $request): int
    {
        if ($this->isSuperAdmin($request)) {
            $request->validate(
                ['branch' => ['required', 'integer', 'exists:branches,id']],
                ['branch.required' => 'اختر الفرع الذي ستُضاف إليه المنتجات.'],
            );

            return (int) $request->input('branch');
        }

        /** @var User $user */
        $user = $request->user();

        return $user->branchId ?? throw ValidationException::withMessages([
            'branch' => 'حسابك غير مرتبط بفرع، فلا وجهة للاستيراد.',
        ]);
    }
}
