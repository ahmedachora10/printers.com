<?php

namespace App\Http\Controllers;

use App\Actions\DeliveryProvider\CreateDeliveryProviderAction;
use App\Actions\DeliveryProvider\DeleteDeliveryProviderAction;
use App\Actions\DeliveryProvider\UpdateDeliveryProviderAction;
use App\Http\Requests\DeliveryProvider\StoreDeliveryProviderRequest;
use App\Http\Requests\DeliveryProvider\UpdateDeliveryProviderRequest;
use App\Models\DeliveryProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DeliveryProviderController extends Controller
{
    public function store(StoreDeliveryProviderRequest $request, CreateDeliveryProviderAction $action): RedirectResponse
    {
        Gate::authorize('create', DeliveryProvider::class);

        $action->handle($request->validated());

        return back(fallback: route('shipping.index'))->with('success', 'تمت إضافة مزوّد التوصيل بنجاح');
    }

    public function update(UpdateDeliveryProviderRequest $request, DeliveryProvider $deliveryProvider, UpdateDeliveryProviderAction $action): RedirectResponse
    {
        Gate::authorize('update', $deliveryProvider);

        $action->handle($deliveryProvider, $request->validated());

        return back(fallback: route('shipping.index'))->with('success', 'تم تحديث مزوّد التوصيل بنجاح');
    }

    public function destroy(DeliveryProvider $deliveryProvider, DeleteDeliveryProviderAction $action): RedirectResponse
    {
        Gate::authorize('delete', $deliveryProvider);

        $action->handle($deliveryProvider);

        return back(fallback: route('shipping.index'))->with('success', 'تم حذف مزوّد التوصيل بنجاح');
    }

    public function toggleStatus(DeliveryProvider $deliveryProvider, UpdateDeliveryProviderAction $action): RedirectResponse
    {
        Gate::authorize('update', $deliveryProvider);

        $action->handle($deliveryProvider, ['is_active' => ! $deliveryProvider->is_active]);

        return back(fallback: route('shipping.index'))->with('success', 'تم تحديث حالة المزوّد بنجاح');
    }
}
