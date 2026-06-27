<?php

namespace App\Http\Controllers;

use App\Actions\City\CreateCityAction;
use App\Actions\City\DeleteCityAction;
use App\Actions\City\UpdateCityAction;
use App\Http\Requests\City\StoreCityRequest;
use App\Http\Requests\City\UpdateCityRequest;
use App\Http\Resources\City\CityResource;
use App\Models\City;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', City::class);

        $cities = City::query()
            ->when($request->input('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('is_active', $request->input('status'));
            })
            ->orderBy('name')
            ->paginate(8);

        return Inertia::render('cities/index', [
            'cities' => CityResource::collection($cities),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', City::class);

        return Inertia::render('cities/create');
    }

    public function store(StoreCityRequest $request, CreateCityAction $action): RedirectResponse
    {
        Gate::authorize('create', City::class);

        $action->handle($request->validated());

        return back(fallback: route('cities.index'));
    }

    public function edit(City $city): Response
    {
        Gate::authorize('update', $city);

        return Inertia::render('cities/edit', [
            'city' => new CityResource($city),
        ]);
    }

    public function update(UpdateCityRequest $request, City $city, UpdateCityAction $action): RedirectResponse
    {
        Gate::authorize('update', $city);

        $action->handle($city, $request->validated());

        return back(fallback: route('cities.index'));
    }

    public function destroy(City $city, DeleteCityAction $action): RedirectResponse
    {
        Gate::authorize('delete', $city);

        $action->handle($city);

        return back(fallback: route('cities.index'));
    }

    public function toggleStatus(City $city, UpdateCityAction $action): RedirectResponse
    {
        Gate::authorize('update', $city);

        $action->handle($city, ['is_active' => ! $city->is_active]);

        return back(fallback: route('cities.index'));
    }
}