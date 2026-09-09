<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Http\Controllers\Concerns\BuildsPagedProps;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use App\Models\ServiceInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تاسك 93 — «كشف توصيلات اليوم»: متابعةٌ تشغيلية لا تسويةُ مستحقّات.
 *
 * طلبه العميل ليعرف كل صباحٍ ما على كل سائق: كم طلباً، وإلى أين، وبكم.
 *
 * **قراءةٌ خالصة**: لا دفعات ولا أرصدة ولا حالة «سُدِّد». تسوية مستحقّات
 * السائقين استُثنيت صراحةً من هذه الدفعة، وأيّ عمودٍ يوحي بها هنا يفتح باباً
 * لنظام مناديب ثانٍ كامل (M26) من حيث لا يُقصد.
 *
 * والاستعلام واحدٌ على `service_invoices` — التوصيل على الخدمات وحدها في هذه
 * المرحلة، فلا اتحاد مع جدول المنتجات ولا حاجة لأعمدة صفرية مقابلة.
 */
class DeliveryLogController extends Controller
{
    use BuildsPagedProps;

    private const PER_PAGE = 25;

    public function index(Request $request, ResolveReportScope $resolveScope): Response
    {
        Gate::authorize('viewAny', DeliveryProvider::class);

        $scope = $resolveScope->handle($request);

        $providerId = $request->filled('provider') ? (int) $request->input('provider') : null;

        // ⚠️ كل عمودٍ مؤهَّلٌ باسم جدوله: `byProvider()` تضمّ `delivery_providers`
        // وفيه `branch_id` كذلك، فعمودٌ مجرَّد يجعل الاستعلام ملتبساً ويسقط.
        $base = ServiceInvoice::query()
            // الفاتورة بلا مزوّد لم تُشحن أصلاً، فلا محلّ لها في كشف السائقين.
            ->whereNotNull('service_invoices.shipping_provider_id')
            // الملغاة والمرتجعة لا رحلة عليها تُتابَع.
            ->whereNotIn('service_invoices.status', ['cancelled', 'returned'])
            ->when($scope['branchId'], fn ($q, $branchId) => $q->where('service_invoices.branch_id', $branchId))
            ->when($providerId, fn ($q, $id) => $q->where('service_invoices.shipping_provider_id', $id))
            // التاريخ بيوم إنشاء الطلب: الرحلة تتبع الطلب لا تحصيله.
            ->whereBetween('service_invoices.created_at', [$scope['from'], $scope['to']]);

        $rows = (clone $base)
            ->with([
                'customer:id,full_name,phone',
                'shippingProvider:id,name,phone',
                'shippingZone:id,name',
                'branch:id,name',
            ])
            ->latest('service_invoices.created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('shipping/deliveries', [
            'deliveries' => $this->pagedProp($rows, fn (ServiceInvoice $invoice) => [
                'id' => $invoice->id,
                'invoiceNumber' => $invoice->invoice_number,
                'createdAt' => $invoice->created_at?->toIso8601String(),
                'customerName' => $invoice->customer?->full_name,
                'customerPhone' => $invoice->customer?->phone,
                'address' => $invoice->shipping_address,
                'zoneName' => $invoice->shippingZone?->name,
                'providerId' => $invoice->shipping_provider_id,
                'providerName' => $invoice->shippingProvider?->name,
                'providerPhone' => $invoice->shippingProvider?->phone,
                'shippingFee' => (float) $invoice->shipping_fee,
                'branchName' => $invoice->branch?->name,
                'statusLabel' => $invoice->status->label(),
            ]),
            // التجميع بالسائق على المدى كلّه لا على الصفحة المعروضة: كشفٌ يقول
            // «لأبي محمد ثلاث رحلات» ثم يعرض اثنتين في الصفحة الأولى كشفٌ يكذب.
            'byProvider' => $this->byProvider(clone $base),
            'totals' => $this->totals(clone $base),
            'providers' => $this->providerOptions($scope['branchId']),
            'branches' => $scope['isSuper']
                ? Branch::query()->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $scope['isSuper'],
            'defaultDate' => now()->toDateString(),
            'filters' => [
                'from' => $scope['from']->toDateString(),
                'to' => $scope['to']->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'provider' => $providerId ? (string) $providerId : null,
            ],
        ]);
    }

    /**
     * صفٌّ لكل سائق: عدد رحلاته وجملة ما تحمّله العميل عليها.
     *
     * @param  Builder<ServiceInvoice>  $base
     * @return array<int, array<string, mixed>>
     */
    private function byProvider($base): array
    {
        return $base
            ->join('delivery_providers', 'delivery_providers.id', '=', 'service_invoices.shipping_provider_id')
            ->groupBy('delivery_providers.id', 'delivery_providers.name', 'delivery_providers.phone')
            ->orderByDesc('deliveries')
            ->get([
                'delivery_providers.id as provider_id',
                'delivery_providers.name as provider_name',
                'delivery_providers.phone as provider_phone',
                DB::raw('COUNT(*) as deliveries'),
                DB::raw('COALESCE(SUM(service_invoices.shipping_fee), 0) as fees'),
            ])
            ->map(fn ($row) => [
                'providerId' => (int) $row->provider_id,
                'providerName' => $row->provider_name,
                'providerPhone' => $row->provider_phone,
                'deliveries' => (int) $row->deliveries,
                'fees' => round((float) $row->fees, 2),
            ])
            ->all();
    }

    /**
     * @param  Builder<ServiceInvoice>  $base
     * @return array<string, float|int>
     */
    private function totals($base): array
    {
        $row = $base->first([
            DB::raw('COUNT(*) as deliveries'),
            DB::raw('COALESCE(SUM(service_invoices.shipping_fee), 0) as fees'),
            DB::raw('COUNT(DISTINCT service_invoices.shipping_provider_id) as providers'),
        ]);

        return [
            'deliveries' => (int) ($row->deliveries ?? 0),
            'fees' => round((float) ($row->fees ?? 0), 2),
            'providers' => (int) ($row->providers ?? 0),
        ];
    }

    /**
     * مزوّدو الفرع للفلتر — النشط منهم وغيره، فكشفُ الأمس يبقى مقروءاً بعد
     * تعطيل سائقٍ اليوم.
     *
     * @return array<int, array<string, mixed>>
     */
    private function providerOptions(?int $branchId): array
    {
        return DeliveryProvider::query()
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DeliveryProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
            ])
            ->all();
    }
}
