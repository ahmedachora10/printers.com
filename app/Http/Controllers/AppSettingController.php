<?php

namespace App\Http\Controllers;

use App\Actions\Setting\UpdateBranchPaymentMethodsAction;
use App\Actions\Setting\UpdateGeneralSettingsAction;
use App\Actions\Setting\UpdateInventoryAlertsAction;
use App\Http\Requests\Setting\UpdateBranchPaymentMethodsRequest;
use App\Http\Requests\Setting\UpdateGeneralSettingsRequest;
use App\Http\Requests\Setting\UpdateInventoryAlertsRequest;
use App\Http\Resources\PaymentMethod\PaymentMethodResource;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AppSettingController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Setting::class);

        $user     = auth()->user();
        $branchId = $user->branchId;
        $branch   = $branchId ? Branch::find($branchId) : null;

        $paymentMethods = PaymentMethod::orderBy('name')->get();

        $enabledPaymentMethodIds = $branchId
            ? json_decode(Setting::get('enabled_payment_methods', $branchId, '[]'), true) ?? []
            : [];

        return Inertia::render('app-settings/index', [
            'generalSettings' => [
                'appName'        => Setting::get('app_name', null, 'مركز الناسخ للطباعة'),
                'defaultVatPct'  => Setting::get('default_vat_pct', null, '15.00'),
                'vatOverridePct' => $branch?->vat_rate_override,
            ],
            'inventoryAlerts' => [
                'minStockAlertThreshold' => Setting::get('min_stock_alert_threshold', $branchId, '10'),
            ],
            'paymentMethods'          => PaymentMethodResource::collection($paymentMethods),
            'enabledPaymentMethodIds' => $enabledPaymentMethodIds,
            'isSuperAdmin'            => $user->roleName->isSuperAdmin(),
        ]);
    }

    public function updateGeneral(UpdateGeneralSettingsRequest $request, UpdateGeneralSettingsAction $action): RedirectResponse
    {
        Gate::authorize('viewAny', Setting::class);

        $action->handle($request->validated(), auth()->user());

        return back()->with('success', 'تم حفظ الإعدادات العامة بنجاح');
    }

    public function updateInventoryAlerts(UpdateInventoryAlertsRequest $request, UpdateInventoryAlertsAction $action): RedirectResponse
    {
        Gate::authorize('viewAny', Setting::class);

        $action->handle($request->validated(), auth()->user()->branchId);

        return back()->with('success', 'تم حفظ إعدادات تنبيهات المخزون بنجاح');
    }

    public function updatePaymentMethods(UpdateBranchPaymentMethodsRequest $request, UpdateBranchPaymentMethodsAction $action): RedirectResponse
    {
        Gate::authorize('viewAny', Setting::class);

        $action->handle($request->validated()['enabled_ids'], auth()->user()->branchId);

        return back()->with('success', 'تم حفظ إعدادات طرق الدفع بنجاح');
    }
}
