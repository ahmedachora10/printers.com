<?php

namespace App\Http\Controllers;

use App\Actions\DeliveryZone\CreateDeliveryZoneAction;
use App\Actions\DeliveryZone\DeleteDeliveryZoneAction;
use App\Actions\DeliveryZone\UpdateDeliveryZoneAction;
use App\Http\Requests\DeliveryZone\StoreDeliveryZoneRequest;
use App\Http\Requests\DeliveryZone\UpdateDeliveryZoneRequest;
use App\Models\DeliveryZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DeliveryZoneController extends Controller
{
    public function store(StoreDeliveryZoneRequest $request, CreateDeliveryZoneAction $action): RedirectResponse
    {
        Gate::authorize('create', DeliveryZone::class);

        $action->handle($request->validated());

        return back(fallback: route('shipping.index'))->with('success', 'تمت إضافة الشريحة بنجاح');
    }

    public function update(UpdateDeliveryZoneRequest $request, DeliveryZone $deliveryZone, UpdateDeliveryZoneAction $action): RedirectResponse
    {
        Gate::authorize('update', $deliveryZone);

        $action->handle($deliveryZone, $request->validated());

        return back(fallback: route('shipping.index'))->with('success', 'تم تحديث الشريحة بنجاح');
    }

    public function destroy(DeliveryZone $deliveryZone, DeleteDeliveryZoneAction $action): RedirectResponse
    {
        Gate::authorize('delete', $deliveryZone);

        $action->handle($deliveryZone);

        return back(fallback: route('shipping.index'))->with('success', 'تم حذف الشريحة بنجاح');
    }

    public function toggleStatus(DeliveryZone $deliveryZone, UpdateDeliveryZoneAction $action): RedirectResponse
    {
        Gate::authorize('update', $deliveryZone);

        $action->handle($deliveryZone, ['is_active' => ! $deliveryZone->is_active]);

        return back(fallback: route('shipping.index'))->with('success', 'تم تحديث حالة الشريحة بنجاح');
    }
}
