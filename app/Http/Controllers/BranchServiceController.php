<?php

namespace App\Http\Controllers;

use App\Actions\BranchService\AttachBranchServiceAction;
use App\Actions\BranchService\DetachBranchServiceAction;
use App\Actions\BranchService\UpdateBranchServiceAction;
use App\Http\Requests\BranchService\StoreBranchServiceRequest;
use App\Http\Requests\BranchService\UpdateBranchServiceRequest;
use App\Http\Resources\BranchService\BranchServiceResource;
use App\Models\BranchService;
use App\Models\ServiceTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BranchServiceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', BranchService::class);

        $userBranch = Auth::user()->branchManager;

        abort_unless($userBranch !== null, 403, 'No owned branch found.');

        $branchId = $userBranch->id;

        $query = BranchService::with(['serviceTemplate', 'branch'])
            ->where('branch_id', $branchId);

        if ($request->filled('search')) {
            $query->whereHas('serviceTemplate', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', (bool) $request->status);
        }

        $branchServices = $query->latest()->paginate(20)->withQueryString();

        $serviceTemplates = ServiceTemplate::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('branch-services/index', [
            'branchServices'   => BranchServiceResource::collection($branchServices),
            'serviceTemplates' => $serviceTemplates,
            'userBranch'       => $userBranch ? ['id' => $userBranch->id, 'name' => $userBranch->name] : null,
            'filters'          => $request->only(['search', 'status']),
        ]);
    }

    public function store(StoreBranchServiceRequest $request, AttachBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('create', BranchService::class);

        $action->handle($request->validated());

        return to_route($this->redirectRoute());
    }

    public function update(UpdateBranchServiceRequest $request, BranchService $branchService, UpdateBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('update', $branchService);

        $action->handle($branchService, $request->validated());

        return to_route($this->redirectRoute());
    }

    public function destroy(BranchService $branchService, DetachBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('delete', $branchService);

        $action->handle($branchService);

        return to_route($this->redirectRoute());
    }

    private function redirectRoute(): string
    {
        return Auth::user()->roleName->isSuperAdmin()
            ? 'service-templates.index'
            : 'branch-services.index';
    }
}
