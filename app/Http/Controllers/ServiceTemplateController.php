<?php

namespace App\Http\Controllers;

use App\Actions\ServiceTemplate\CreateServiceTemplateAction;
use App\Actions\ServiceTemplate\DeleteServiceTemplateAction;
use App\Actions\ServiceTemplate\UpdateServiceTemplateAction;
use App\Http\Requests\ServiceTemplate\StoreServiceTemplateRequest;
use App\Http\Requests\ServiceTemplate\UpdateServiceTemplateRequest;
use App\Http\Resources\ServiceTemplate\ServiceTemplateResource;
use App\Models\Branch;
use App\Models\ServiceTemplate;
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
                fn ($q) => $q->where('name', 'like', '%' . $request->input('search') . '%')
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('is_active', $request->boolean('status'))
            )
            ->latest()
            ->paginate(15);

        return Inertia::render('service-templates/index', [
            'templates' => ServiceTemplateResource::collection($templates),
            'branches'  => Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters'   => [
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
