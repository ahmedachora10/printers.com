<?php

namespace App\Http\Controllers;

use App\Actions\Agent\CreateAgentAction;
use App\Actions\Agent\DeleteAgentAction;
use App\Actions\Agent\UpdateAgentAction;
use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentTypeEnum;
use App\Http\Requests\Agent\StoreAgentRequest;
use App\Http\Requests\Agent\UpdateAgentRequest;
use App\Http\Resources\Agent\AgentResource;
use App\Models\Agent;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Agent::class);

        $branchId = auth()->user()->branchId ?? null;

        $items = Agent::query()
            ->with(['branch', 'agentProfile', 'agentBranches:id,name'])
            // An agent may be linked to several branches, so visibility follows
            // the links rather than the primary branch_id column.
            // Branch-admins/accountants are locked to their own branch.
            ->when($branchId, fn ($q) => $q->forBranch($branchId))
            // Super-admins (no own branch) may optionally narrow to one branch.
            ->when(! $branchId && $request->filled('branch_id'), fn ($q) => $q->forBranch((int) $request->input('branch_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $search = $request->input('search');
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            }))
            ->when($request->filled('agent_type'), fn ($q) => $q->whereHas(
                'agentProfile',
                fn (Builder $p) => $p->where('agent_type', $request->input('agent_type'))
            ))
            ->when($request->filled('discount_mode'), fn ($q) => $q->whereHas(
                'agentProfile',
                fn (Builder $p) => $p->where('discount_mode', $request->input('discount_mode'))
            ))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('agents/index', [
            'items' => AgentResource::collection($items),
            'agentTypes' => $this->enumOptions(AgentTypeEnum::cases()),
            'discountModes' => $this->enumOptions(AgentDiscountModeEnum::cases()),
            'branches' => auth()->user()->roleName?->isSuperAdmin()
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
            'filters' => [
                'search' => $request->input('search'),
                'agent_type' => $request->input('agent_type'),
                'discount_mode' => $request->input('discount_mode'),
                'status' => $request->input('status'),
                'branch_id' => $request->input('branch_id'),
            ],
        ]);
    }

    public function store(StoreAgentRequest $request, CreateAgentAction $action): RedirectResponse
    {
        Gate::authorize('create', Agent::class);

        $action->handle($request->validated());

        return back(fallback: route('agents.index'))->with('success', 'تم إضافة المندوب بنجاح');
    }

    public function update(UpdateAgentRequest $request, Agent $agent, UpdateAgentAction $action): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $action->handle($agent, $request->validated());

        return back(fallback: route('agents.index'))->with('success', 'تم تحديث بيانات المندوب بنجاح');
    }

    public function destroy(Agent $agent, DeleteAgentAction $action): RedirectResponse
    {
        Gate::authorize('delete', $agent);

        $action->handle($agent);

        return back(fallback: route('agents.index'))->with('success', 'تم حذف المندوب بنجاح');
    }

    /**
     * @param  array<int, AgentTypeEnum|AgentDiscountModeEnum>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }
}
