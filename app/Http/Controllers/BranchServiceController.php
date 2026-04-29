<?php

namespace App\Http\Controllers;

use App\Actions\BranchService\AttachBranchServiceAction;
use App\Actions\BranchService\DetachBranchServiceAction;
use App\Actions\BranchService\UpdateBranchServiceAction;
use App\Http\Requests\BranchService\StoreBranchServiceRequest;
use App\Http\Requests\BranchService\UpdateBranchServiceRequest;
use App\Models\BranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class BranchServiceController extends Controller
{
    public function store(StoreBranchServiceRequest $request, AttachBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('create', BranchService::class);

        $action->handle($request->validated());

        return to_route('service-templates.index');
    }

    public function update(UpdateBranchServiceRequest $request, BranchService $branchService, UpdateBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('update', $branchService);

        $action->handle($branchService, $request->validated());

        return to_route('service-templates.index');
    }

    public function destroy(BranchService $branchService, DetachBranchServiceAction $action): RedirectResponse
    {
        Gate::authorize('delete', $branchService);

        $action->handle($branchService);

        return to_route('service-templates.index');
    }
}
