<?php

namespace App\Http\Controllers;

use App\Actions\Branch\CreateBranchAction;
use App\Actions\Branch\DeleteBranchAction;
use App\Actions\Branch\UpdateBranchAction;
use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\Branch\BranchResource;
use App\Http\Resources\City\CityResource;
use App\Models\Branch;
use App\Models\City;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Branch::class);

        $branches = Branch::query()
            ->with(['city', 'owner'])
            ->when($request->input('search'), fn ($q) => $q->where('name', 'like', '%' . $request->input('search') . '%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->input('status')))
            ->latest()
            ->paginate(12);

        return Inertia::render('branches/index', [
            'branches'     => BranchResource::collection($branches),
            'cities'       => CityResource::collection(
                City::query()->where('is_active', true)->orderBy('name')->get()
            ),
            'branchAdmins' => User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'branch-admin'))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters'      => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Branch::class);

        return Inertia::render('branches/create', [
            'cities' => CityResource::collection(
                City::query()->where('is_active', true)->orderBy('name')->get()
            ),
        ]);
    }

    public function store(StoreBranchRequest $request, CreateBranchAction $action): RedirectResponse
    {
        Gate::authorize('create', Branch::class);

        $action->handle($request->validated());

        return back(fallback: route('branches.index'));
    }

    public function edit(Branch $branch): Response
    {
        Gate::authorize('update', $branch);

        return Inertia::render('branches/edit', [
            'branch' => new BranchResource($branch),
            'cities' => CityResource::collection(
                City::query()->where('is_active', true)->orderBy('name')->get()
            ),
        ]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch, UpdateBranchAction $action): RedirectResponse
    {
        Gate::authorize('update', $branch);

        $action->handle($branch, $request->validated());

        return back(fallback: route('branches.index'));
    }

    public function destroy(Branch $branch, DeleteBranchAction $action): RedirectResponse
    {
        Gate::authorize('delete', $branch);

        $action->handle($branch);

        return back(fallback: route('branches.index'));
    }

    public function toggleStatus(Branch $branch, UpdateBranchAction $action): RedirectResponse
    {
        Gate::authorize('update', $branch);

        $action->handle($branch, ['is_active' => ! $branch->is_active]);

        return back(fallback: route('branches.index'));
    }
}
