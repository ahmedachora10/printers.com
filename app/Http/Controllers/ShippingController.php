<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryProviderTypeEnum;
use App\Enums\DeliveryZoneTypeEnum;
use App\Http\Resources\Shipping\DeliveryProviderResource;
use App\Http\Resources\Shipping\DeliveryZoneResource;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تاسك 93 — شاشة التوصيل: المزوّدون والشرائح في صفحةٍ واحدة بتبويبين.
 *
 * صفحةٌ مستقلة لا تبويبٌ في `/app-settings`: الشرائح جدولُ تسعيرٍ يُراجَع
 * ويُعدَّل، لا إعدادٌ يُضبط مرّة.
 *
 * القراءة هنا والكتابة في كنترولرَي المزوّد والشريحة — فكلٌّ منهما مورد
 * قائمٌ بذاته له سياسته وطلباته، والشاشة تجمع عرضَهما فقط.
 */
class ShippingController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', DeliveryProvider::class);

        $user = $request->user();
        $isSuperAdmin = (bool) $user?->roleName->isSuperAdmin();

        // السوبر أدمن يقرأ الفروع كلها ما لم يختر فرعاً؛ ومدير الفرع مقيَّدٌ
        // بفرعه مهما أرسل في الطلب — فالفرع يُقرأ من المستخدم لا من الاستعلام.
        $branchId = $isSuperAdmin
            ? ($request->filled('branch_id') ? (int) $request->input('branch_id') : null)
            : $user?->branchId;

        $providers = DeliveryProvider::query()
            ->with('branch:id,name')
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->orderBy('name')
            ->get();

        $zones = DeliveryZone::query()
            ->with('branch:id,name')
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->ordered()
            ->get();

        return Inertia::render('shipping/index', [
            'providers' => DeliveryProviderResource::collection($providers),
            'zones' => DeliveryZoneResource::collection($zones),
            'providerTypes' => $this->options(DeliveryProviderTypeEnum::cases()),
            'zoneTypes' => $this->options(DeliveryZoneTypeEnum::cases()),
            'canManage' => Gate::allows('create', DeliveryProvider::class),
            'isSuperAdmin' => $isSuperAdmin,
            // منتقي الفرع للسوبر أدمن وحده: العمود إلزاميّ ولا فرع له هو،
            // فلا بدّ أن يختار فرعاً قبل الإضافة.
            'branches' => $isSuperAdmin
                ? Branch::query()->orderBy('name')->get(['id', 'name'])
                : [],
            'filters' => [
                'branch_id' => $request->input('branch_id'),
            ],
        ]);
    }

    /**
     * @param  array<int, DeliveryProviderTypeEnum|DeliveryZoneTypeEnum>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }
}
