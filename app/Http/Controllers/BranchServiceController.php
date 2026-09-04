<?php

namespace App\Http\Controllers;

use App\Actions\BranchService\AttachBranchServiceAction;
use App\Actions\BranchService\DetachBranchServiceAction;
use App\Actions\BranchService\SyncBranchServiceMaterialsAction;
use App\Actions\BranchService\UpdateBranchServiceAction;
use App\Actions\UserService\SyncUserServiceCommissionsAction;
use App\Enums\Roles;
use App\Exports\BranchServicesExport;
use App\Exports\BranchServicesTemplateExport;
use App\Http\Controllers\Concerns\RunsExcelImports;
use App\Http\Requests\BranchService\StoreBranchServiceRequest;
use App\Http\Requests\BranchService\UpdateBranchServiceMaterialsRequest;
use App\Http\Requests\BranchService\UpdateBranchServiceRequest;
use App\Http\Requests\BranchService\UpdateEmployeeCommissionsRequest;
use App\Http\Resources\BranchService\BranchServiceResource;
use App\Imports\BranchServicesImport;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Product;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BranchServiceController extends Controller
{
    use RunsExcelImports;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', BranchService::class);

        $userBranch = $this->ownedBranch();

        $branchId = $userBranch->id;

        $query = BranchService::with(['serviceTemplate', 'branch', 'materials.product.unit'])
            ->where('branch_id', $branchId);

        if ($request->filled('search')) {
            $query->whereHas('serviceTemplate', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', (bool) $request->status);
        }

        $branchServices = $query->latest()->paginate(20)->withQueryString();

        // الخدمات العامة + ما أنشأه هذا الفرع لنفسه (تاسك 45) — خدمات الفروع
        // الأخرى لا تظهر هنا.
        $serviceTemplates = ServiceTemplate::query()
            ->where('is_active', true)
            ->availableToBranch($branchId)
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id'])
            ->map(fn (ServiceTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'isOwn' => $t->branch_id !== null,
            ])
            ->values();

        // Branch employees who can earn service commission, plus the per-employee
        // rates already set for the services on this page — so the service-side
        // editor can list every employee with their current rate.
        $employees = User::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', Roles::EMPLOYEE->value))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        $serviceIds = collect($branchServices->items())->pluck('id');

        $employeeCommissions = UserService::query()
            ->whereIn('branch_service_id', $serviceIds)
            ->get(['branch_service_id', 'user_id', 'commission_override_pct'])
            ->groupBy('branch_service_id')
            ->map(fn ($rows) => $rows->map(fn (UserService $r) => [
                'userId' => $r->user_id,
                'commissionPct' => (float) $r->commission_override_pct,
            ])->values());

        // منتجات الفرع التي يجوز تعريفها خامةً لخدمة (تاسك 50). المنتج المسعّر
        // بالمتر المربع يُستهلك بالمتر، وغيره بوحدته — والتسمية تقول ذلك للمستخدم.
        $products = Product::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('unit:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit_id', 'is_sqm'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'unitName' => $p->is_sqm ? 'متر مربع' : $p->unit?->name,
                'isSqm' => (bool) $p->is_sqm,
            ])
            ->values();

        return Inertia::render('branch-services/index', [
            'branchServices' => BranchServiceResource::collection($branchServices),
            'serviceTemplates' => $serviceTemplates,
            'products' => $products,
            'userBranch' => ['id' => $userBranch->id, 'name' => $userBranch->name],
            'employees' => $employees,
            'employeeCommissions' => $employeeCommissions,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function store(StoreBranchServiceRequest $request, AttachBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('create', BranchService::class);

        $action->handle($request->validated());

        return back(fallback: route($this->redirectRoute()));
    }

    public function update(UpdateBranchServiceRequest $request, BranchService $branchService, UpdateBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('update', $branchService);

        $action->handle($branchService, $request->validated());

        return back(fallback: route($this->redirectRoute()));
    }

    public function destroy(BranchService $branchService, DetachBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('delete', $branchService);

        $action->handle($branchService);

        return back(fallback: route($this->redirectRoute()));
    }

    /**
     * تاسك 50: خامات المخزون لهذه الخدمة. القائمة المرسلة هي القائمة كاملة —
     * ما لم يَرِد فيها يُحذف من التعريف (ولا يمسّ ذلك حركات المخزون السابقة).
     */
    public function updateMaterials(
        UpdateBranchServiceMaterialsRequest $request,
        BranchService $branchService,
        SyncBranchServiceMaterialsAction $action,
    ): RedirectResponse {
        Gate::authorize('update', $branchService);

        $action->handle($branchService, array_map(fn (array $row) => [
            'product_id' => (int) $row['product_id'],
            'qty_per_unit' => (float) $row['qty_per_unit'],
            'waste_pct' => (float) ($row['waste_pct'] ?? 0),
        ], $request->validated('materials')));

        return back()->with('success', 'تم تحديث خامات الخدمة بنجاح');
    }

    /**
     * Set per-employee commission rates for this service. An employee sent with a
     * null rate is cleared back to 0% commission for the service.
     */
    public function updateEmployeeCommissions(
        UpdateEmployeeCommissionsRequest $request,
        BranchService $branchService,
        SyncUserServiceCommissionsAction $action,
    ): RedirectResponse {
        Gate::authorize('update', $branchService);

        $pairs = array_map(fn (array $row) => [
            'user_id' => (int) $row['user_id'],
            'branch_service_id' => $branchService->id,
            'commission_pct' => isset($row['commission_pct']) ? (float) $row['commission_pct'] : null,
        ], $request->validated('commissions'));

        $action->handle($pairs);

        return back()->with('success', 'تم تحديث عمولات الموظفين بنجاح');
    }

    /** ورقتا الخدمات وعمولات الموظفين لهذا الفرع — انظر BranchServicesExport. */
    public function export(): BinaryFileResponse
    {
        Gate::authorize('viewAny', BranchService::class);

        return Excel::download(
            new BranchServicesExport($this->ownedBranch()->id),
            'branch-services-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function importPreview(Request $request): JsonResponse
    {
        Gate::authorize('create', BranchService::class);

        $branchId = $this->ownedBranch()->id;

        return $this->previewImport($request, fn (bool $dryRun) => new BranchServicesImport($branchId, $dryRun));
    }

    public function import(Request $request): JsonResponse
    {
        Gate::authorize('create', BranchService::class);

        $branchId = $this->ownedBranch()->id;

        return $this->commitImport($request, fn (bool $dryRun) => new BranchServicesImport($branchId, $dryRun));
    }

    public function importTemplate(): BinaryFileResponse
    {
        Gate::authorize('create', BranchService::class);

        return Excel::download(new BranchServicesTemplateExport, 'branch-services-template.xlsx');
    }

    /**
     * الفرع الذي تعمل عليه الشاشة كلّها: فرعُ مديرِها. ومن لا فرع يملكه لا شيء
     * له هنا يراه أو يستورد إليه — والسوبر أدمن يصل الخدمات من شاشة القوالب.
     */
    private function ownedBranch(): Branch
    {
        $branch = Auth::user()->branchManager;

        abort_unless($branch !== null, 403, 'No owned branch found.');

        return $branch;
    }

    private function redirectRoute(): string
    {
        return Auth::user()->roleName->isSuperAdmin()
            ? 'service-templates.index'
            : 'branch-services.index';
    }
}
