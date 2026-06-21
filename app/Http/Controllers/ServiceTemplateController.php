<?php

namespace App\Http\Controllers;

use App\Actions\ServiceTemplate\CreateServiceTemplateAction;
use App\Actions\ServiceTemplate\DeleteServiceTemplateAction;
use App\Actions\ServiceTemplate\UpdateServiceTemplateAction;
use App\Enums\Roles;
use App\Http\Requests\ServiceTemplate\StoreServiceTemplateRequest;
use App\Http\Requests\ServiceTemplate\UpdateServiceTemplateRequest;
use App\Http\Resources\ServiceTemplate\ServiceTemplateResource;
use App\Models\Branch;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ServiceTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ServiceTemplate::class);

        $templates = ServiceTemplate::query()
            ->with('branches')
            ->when(
                $request->input('search'),
                fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%')
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('is_active', $request->boolean('status'))
            )
            ->latest()
            ->paginate(15);

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Active employees per branch, plus the per-employee rates already set for
        // the branch services on this page — so the service-side editor can list
        // every employee with their current rate. Keyed for O(1) modal lookups.
        $branchEmployees = User::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', Roles::EMPLOYEE->value))
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id'])
            ->groupBy('branch_id')
            ->map(fn ($rows) => $rows->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values());

        $serviceIds = $templates->getCollection()
            ->flatMap(fn (ServiceTemplate $t) => $t->branches->pluck('pivot.id'))
            ->filter()
            ->all();

        $employeeCommissions = UserService::query()
            ->whereIn('branch_service_id', $serviceIds)
            ->get(['branch_service_id', 'user_id', 'commission_override_pct'])
            ->groupBy('branch_service_id')
            ->map(fn ($rows) => $rows->map(fn (UserService $r) => [
                'userId' => $r->user_id,
                'commissionPct' => (float) $r->commission_override_pct,
            ])->values());

        return Inertia::render('service-templates/index', [
            'templates' => ServiceTemplateResource::collection($templates),
            'branches' => $branches,
            'branchEmployees' => $branchEmployees,
            'employeeCommissions' => $employeeCommissions,
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreServiceTemplateRequest $request, CreateServiceTemplateAction $action): RedirectResponse
    {
        Gate::authorize('create', ServiceTemplate::class);

        $action->handle($request->validated());

        return to_route('service-templates.index');
    }

    public function update(UpdateServiceTemplateRequest $request, ServiceTemplate $serviceTemplate, UpdateServiceTemplateAction $action): RedirectResponse
    {
        Gate::authorize('update', $serviceTemplate);

        $action->handle($serviceTemplate, $request->validated());

        return to_route('service-templates.index');
    }

    public function destroy(ServiceTemplate $serviceTemplate, DeleteServiceTemplateAction $action): RedirectResponse
    {
        Gate::authorize('delete', $serviceTemplate);

        $action->handle($serviceTemplate);

        return to_route('service-templates.index');
    }
}
