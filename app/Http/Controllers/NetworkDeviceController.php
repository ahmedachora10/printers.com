<?php

namespace App\Http\Controllers;

use App\Actions\NetworkDevice\SaveNetworkDeviceAction;
use App\Http\Requests\NetworkDevice\SaveNetworkDeviceRequest;
use App\Models\NetworkDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * تاسك 146 — أجهزة الشبكة (تبويب في الإعدادات). الحذف ناعم: صفوف المطابقة
 * القديمة تبقى تشير إلى الجهاز ويقرؤها التقرير.
 */
class NetworkDeviceController extends Controller
{
    public function store(SaveNetworkDeviceRequest $request, SaveNetworkDeviceAction $action): RedirectResponse
    {
        Gate::authorize('create', NetworkDevice::class);

        $action->handle($request->validated());

        return back()->with('success', 'تم إضافة الجهاز');
    }

    public function update(SaveNetworkDeviceRequest $request, NetworkDevice $networkDevice, SaveNetworkDeviceAction $action): RedirectResponse
    {
        Gate::authorize('update', $networkDevice);

        $action->handle($request->validated(), $networkDevice);

        return back()->with('success', 'تم تحديث الجهاز');
    }

    public function destroy(NetworkDevice $networkDevice): RedirectResponse
    {
        Gate::authorize('delete', $networkDevice);

        $networkDevice->delete();

        return back()->with('success', 'تم حذف الجهاز');
    }
}
