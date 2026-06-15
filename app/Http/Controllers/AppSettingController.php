<?php

namespace App\Http\Controllers;

use App\Actions\Setting\UpdateBranchPaymentMethodsAction;
use App\Actions\Setting\UpdateGeneralSettingsAction;
use App\Actions\Setting\UpdateInventoryAlertsAction;
use App\Actions\Setting\UpdateLoyaltyConfigAction;
use App\Http\Requests\Setting\UpdateBranchPaymentMethodsRequest;
use App\Http\Requests\Setting\UpdateGeneralSettingsRequest;
use App\Http\Requests\Setting\UpdateInventoryAlertsRequest;
use App\Http\Requests\Setting\UpdateLoyaltyConfigRequest;
use App\Http\Resources\PaymentMethod\PaymentMethodResource;
use App\Models\Branch;
use App\Models\LoyaltyConfig;
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

        $user = auth()->user();
        $branchId = $user->branchId;
        $branch = $branchId ? Branch::find($branchId) : null;

        $paymentMethods = PaymentMethod::orderBy('name')->get();

        $enabledPaymentMethodIds = $branchId
            ? json_decode(Setting::get('enabled_payment_methods', $branchId, '[]'), true) ?? []
            : [];

        $loyaltyConfig = $branchId ? LoyaltyConfig::forBranch($branchId) : null;

        return Inertia::render('app-settings/index', [
            'generalSettings' => [
                'appName' => Setting::get('app_name', null, 'مركز الناسخ للطباعة'),
                'defaultVatPct' => Setting::get('default_vat_pct', null, '15.00'),
                'vatOverridePct' => $branch?->vat_rate_override,
            ],
            'inventoryAlerts' => [
                'minStockAlertThreshold' => Setting::get('min_stock_alert_threshold', $branchId, '10'),
            ],
            'paymentMethods' => PaymentMethodResource::collection($paymentMethods),
            'enabledPaymentMethodIds' => $enabledPaymentMethodIds,
            'isSuperAdmin' => $user->roleName->isSuperAdmin(),
            'loyaltyConfig' => $loyaltyConfig ? [
                'isActive' => (bool) $loyaltyConfig->is_active,
                'earningRate' => (float) $loyaltyConfig->earning_rate,
                'redemptionRate' => (float) $loyaltyConfig->redemption_rate,
                'minRedemptionPoints' => (int) $loyaltyConfig->min_redemption_points,
                'bronzeThreshold' => (float) $loyaltyConfig->bronze_threshold,
                'silverThreshold' => (float) $loyaltyConfig->silver_threshold,
                'goldThreshold' => (float) $loyaltyConfig->gold_threshold,
                'bronzeDiscountPct' => (float) $loyaltyConfig->bronze_discount_pct,
                'silverDiscountPct' => (float) $loyaltyConfig->silver_discount_pct,
                'goldDiscountPct' => (float) $loyaltyConfig->gold_discount_pct,
            ] : null,
            'canConfigureLoyalty' => $user->hasPermission('configure-loyalty'),
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

    public function updateLoyalty(UpdateLoyaltyConfigRequest $request, UpdateLoyaltyConfigAction $action): RedirectResponse
    {
        $branchId = auth()->user()->branchId;

        abort_if($branchId === null, 403, 'لا يمكن إعداد برنامج الولاء بدون فرع.');

        $action->handle($request->validated(), $branchId);

        return back()->with('success', 'تم حفظ إعدادات برنامج الولاء بنجاح');
    }
}
