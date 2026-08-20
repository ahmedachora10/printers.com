<?php

namespace App\Http\Controllers;

use App\Actions\Branch\UpdateBranchProfileAction;
use App\Actions\Setting\UpdateBranchPaymentMethodsAction;
use App\Actions\Setting\UpdateGeneralSettingsAction;
use App\Actions\Setting\UpdateInventoryAlertsAction;
use App\Actions\Setting\UpdateLoyaltyConfigAction;
use App\Http\Requests\Branch\UpdateBranchProfileRequest;
use App\Http\Requests\Setting\UpdateBranchPaymentMethodsRequest;
use App\Http\Requests\Setting\UpdateGeneralSettingsRequest;
use App\Http\Requests\Setting\UpdateInventoryAlertsRequest;
use App\Http\Requests\Setting\UpdateLoyaltyConfigRequest;
use App\Http\Resources\Branch\BranchResource;
use App\Http\Resources\City\CityResource;
use App\Http\Resources\PaymentMethod\PaymentMethodResource;
use App\Models\Branch;
use App\Models\City;
use App\Models\LoyaltyConfig;
use App\Models\PaymentMethod;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AppSettingController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Setting::class);

        $user = auth()->user();
        $branchId = $user->branchId;
        $branch = $branchId ? Branch::find($branchId) : null;

        // تاسك 59: الفرع يرى الطرق العامة + ما أضافه هو؛ والسوبر أدمن يرى الكل.
        $paymentMethods = PaymentMethod::query()
            ->with('branch:id,name')
            ->visibleToBranch($user->roleName->isSuperAdmin() ? null : $branchId)
            ->orderBy('name')
            ->get();

        $enabledPaymentMethodIds = $branchId
            ? json_decode(Setting::get('enabled_payment_methods', $branchId, '[]'), true) ?? []
            : [];

        // تاسك 52: السوبر أدمن بلا فرع كان لا يجد أين يفعّل برنامج الولاء أصلاً.
        // فصار له منتقي فرع يحمّل إعداداته ويحفظ عليها، ويهبط على أول فرع بدل
        // شاشة فارغة. ومن سواه يبقى مربوطاً بفرعه لا يراه المنتقي.
        $isSuperAdmin = $user->roleName->isSuperAdmin();

        $loyaltyBranches = $isSuperAdmin
            ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        $loyaltyBranchId = $isSuperAdmin
            ? $this->resolveLoyaltyBranchId($request, $loyaltyBranches)
            : $branchId;

        $loyaltyConfig = $loyaltyBranchId ? LoyaltyConfig::forBranch($loyaltyBranchId) : null;

        return Inertia::render('app-settings/index', [
            'generalSettings' => [
                'appName' => Setting::get('app_name', null, 'مركز الناسخ للطباعة'),
                'defaultVatPct' => Setting::get('default_vat_pct', null, '15.00'),
            ],
            // Only a branch's own manager gets the branch-data tab; super-admins
            // edit every branch on /branches instead.
            'branchProfile' => $branch && Gate::allows('update', $branch)
                ? new BranchResource($branch->load('city'))
                : null,
            'cities' => CityResource::collection(
                City::query()->where('is_active', true)->orderBy('name')->get()
            ),
            'inventoryAlerts' => [
                'minStockAlertThreshold' => Setting::get('min_stock_alert_threshold', $branchId, '10'),
            ],
            'paymentMethods' => PaymentMethodResource::collection($paymentMethods),
            'enabledPaymentMethodIds' => $enabledPaymentMethodIds,
            'canManagePaymentMethods' => Gate::allows('create', PaymentMethod::class),
            'isSuperAdmin' => $isSuperAdmin,
            'loyaltyConfig' => $loyaltyConfig ? [
                'isActive' => (bool) $loyaltyConfig->is_active,
                'earningRate' => (float) $loyaltyConfig->earning_rate,
                'redemptionRate' => (float) $loyaltyConfig->redemption_rate,
                'minRedemptionPoints' => (int) $loyaltyConfig->min_redemption_points,
                'expiryMonths' => $loyaltyConfig->expiry_months,
                'bronzeThreshold' => (float) $loyaltyConfig->bronze_threshold,
                'silverThreshold' => (float) $loyaltyConfig->silver_threshold,
                'goldThreshold' => (float) $loyaltyConfig->gold_threshold,
                'bronzeDiscountPct' => (float) $loyaltyConfig->bronze_discount_pct,
                'silverDiscountPct' => (float) $loyaltyConfig->silver_discount_pct,
                'goldDiscountPct' => (float) $loyaltyConfig->gold_discount_pct,
            ] : null,
            'canConfigureLoyalty' => $user->hasPermission('configure-loyalty'),
            // الفروع تُمرَّر للسوبر أدمن وحده — والمعرّف يعود ليَصدُقَ المنتقي
            // عمّا هو معروض فعلاً بعد تصحيح أي قيمة لا تخصّ فرعاً قائماً.
            'loyaltyBranches' => $loyaltyBranches->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
            ])->values(),
            'loyaltyBranchId' => $loyaltyBranchId,
        ]);
    }

    /**
     * الفرع المعروض في تبويب الولاء للسوبر أدمن: ما اختاره إن كان فرعاً قائماً،
     * وإلا أول الفروع — فلا يقف أمام شاشةٍ فارغة ولا أمام قيمةٍ لا يملكها.
     *
     * @param  Collection<int, Branch>  $branches
     */
    private function resolveLoyaltyBranchId(Request $request, Collection $branches): ?int
    {
        $requested = $request->filled('loyaltyBranch') ? (int) $request->input('loyaltyBranch') : null;

        if ($requested !== null && $branches->contains('id', $requested)) {
            return $requested;
        }

        return $branches->first()?->id;
    }

    public function updateGeneral(UpdateGeneralSettingsRequest $request, UpdateGeneralSettingsAction $action): RedirectResponse
    {
        Gate::authorize('viewAny', Setting::class);

        $action->handle($request->validated(), auth()->user());

        return back()->with('success', 'تم حفظ الإعدادات العامة بنجاح');
    }

    public function updateBranchProfile(UpdateBranchProfileRequest $request, UpdateBranchProfileAction $action): RedirectResponse
    {
        $branchId = auth()->user()->branchId;

        abort_if($branchId === null, 403, 'لا يمكن تعديل بيانات الفرع بدون فرع محدد.');

        $branch = Branch::findOrFail($branchId);

        Gate::authorize('update', $branch);

        $action->handle($branch, $request->validated(), auth()->user());

        return back()->with('success', 'تم حفظ بيانات الفرع بنجاح');
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
        $data = $request->validated();

        // تاسك 52: `branch_id` لا يصل إلا من السوبر أدمن — الطلب يُسقطه ممّن
        // سواه، فيبقى كلُّ مديرِ فرعٍ محبوساً في فرعه مهما أرسل.
        $branchId = $data['branch_id'] ?? auth()->user()->branchId;
        unset($data['branch_id']);

        abort_if($branchId === null, 403, 'لا يمكن إعداد برنامج الولاء بدون فرع.');

        $action->handle($data, (int) $branchId);

        return back()->with('success', 'تم حفظ إعدادات برنامج الولاء بنجاح');
    }
}
