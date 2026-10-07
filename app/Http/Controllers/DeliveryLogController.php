<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Http\Controllers\Concerns\BuildsPagedProps;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use App\Models\ExpenseCategory;
use App\Models\ServiceInvoiceShipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * تاسك 111: تسوية أجر السائق **لكل طلب** (DeliverySettlementController) — مصروفٌ
 * مربوطٌ بالطلب لا رصيدٌ للسائق. ما زال خارج الكشف: أرصدة السائقين والدفعات
 * المجمَّعة، وأيّ منهما يفتح باباً لنظام مناديب ثانٍ (M26).
 *
 * والاستعلام واحدٌ على `service_invoice_shipments` مع فاتورته — التوصيل على
 * الخدمات وحدها، فلا اتحاد مع جدول المنتجات. وتاسك 170: الصفّ طلبُ توصيل لا
 * فاتورة، ففاتورةٌ بسائقين صفّان.
 */
class DeliveryLogController extends Controller
{
    use BuildsPagedProps;

    private const PER_PAGE = 25;

    public function index(Request $request, ResolveReportScope $resolveScope): Response
    {
        // تاسك 127: قراءة الكشف — المحاسب فيها، وإدارة السائقين ليست.
        Gate::authorize('viewDeliveries', DeliveryProvider::class);

        $scope = $resolveScope->handle($request);

        $providerId = $request->filled('provider') ? (int) $request->input('provider') : null;

        // ⚠️ كل عمودٍ مؤهَّلٌ باسم جدوله: `byProvider()` تضمّ `delivery_providers`
        // وفيه `branch_id` كذلك، فعمودٌ مجرَّد يجعل الاستعلام ملتبساً ويسقط.
        $base = ServiceInvoiceShipment::query()
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_shipments.service_invoice_id')
            ->whereNull('service_invoices.deleted_at')
            // الطلب بلا مزوّد لم يُشحن أصلاً، فلا محلّ له في كشف السائقين.
            ->whereNotNull('service_invoice_shipments.provider_id')
            // الملغاة والمرتجعة لا رحلة عليها تُتابَع.
            ->whereNotIn('service_invoices.status', ['cancelled', 'returned'])
            ->when($scope['branchId'], fn ($q, $branchId) => $q->where('service_invoices.branch_id', $branchId))
            ->when($providerId, fn ($q, $id) => $q->where('service_invoice_shipments.provider_id', $id))
            // التاريخ بيوم إنشاء الطلب: الرحلة تتبع الطلب لا تحصيله.
            ->whereBetween('service_invoices.created_at', [$scope['from'], $scope['to']]);

        $rows = (clone $base)
            ->select('service_invoice_shipments.*')
            ->with([
                'invoice.customer:id,full_name,phone',
                'invoice.branch:id,name',
                'invoice.user:id,name',
                'provider:id,name,phone',
                'zone:id,name',
                'settlement.user:id,name',
            ])
            ->orderByDesc('service_invoices.created_at')
            ->orderBy('service_invoice_shipments.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // التجميع بالسائق على المدى كلّه لا على الصفحة المعروضة: كشفٌ يقول
        // «لأبي محمد ثلاث رحلات» ثم يعرض اثنتين في الصفحة الأولى كشفٌ يكذب.
        $byProvider = $this->byProvider(clone $base);

        return Inertia::render('shipping/deliveries', [
            'deliveries' => $this->pagedProp($rows, fn (ServiceInvoiceShipment $shipment) => [
                'id' => $shipment->id,
                'invoiceId' => $shipment->service_invoice_id,
                'invoiceNumber' => $shipment->invoice->invoice_number,
                'createdAt' => $shipment->invoice->created_at?->toIso8601String(),
                // الموظف الذي أصدر الفاتورة.
                'employeeName' => $shipment->invoice->user?->name,
                'customerName' => $shipment->invoice->customer?->full_name,
                'customerPhone' => $shipment->invoice->customer?->phone,
                'address' => $shipment->address,
                'zoneName' => $shipment->zone?->name,
                'providerId' => $shipment->provider_id,
                'providerName' => $shipment->provider?->name,
                'providerPhone' => $shipment->provider?->phone,
                'shippingFee' => (float) $shipment->fee,
                'branchName' => $shipment->invoice->branch?->name,
                'statusLabel' => $shipment->invoice->status->label(),
                'branchId' => $shipment->invoice->branch_id,
                // تاسك 111 — تسوية أجر السائق، مستقلةٌ عن حالة سداد العميل أعلاه.
                'settlement' => $shipment->settlement ? [
                    'expenseId' => $shipment->settlement->id,
                    'amount' => (float) $shipment->settlement->total,
                    'paidFromLabel' => $shipment->settlement->paid_from->label(),
                    'settledByName' => $shipment->settlement->user?->name,
                    'settledAt' => $shipment->settlement->created_at?->toIso8601String(),
                ] : null,
            ]),
            'byProvider' => $byProvider,
            // الجُمل مقروءةٌ من صفوف السائقين نفسها — استعلامٌ ثالثٌ يعيد جمع
            // ما جُمع للتوّ.
            'totals' => [
                'deliveries' => array_sum(array_column($byProvider, 'deliveries')),
                'fees' => round(array_sum(array_column($byProvider, 'fees')), 2),
                'providers' => count($byProvider),
            ],
            'providers' => $this->providerOptions($scope['branchId']),
            'branches' => $scope['isSuper']
                ? Branch::query()->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $scope['isSuper'],
            // الزرّ يتبع السياسة (المديران والمحاسب، تاسك 152)، وهي تُعاد فحصها
            // في DeliverySettlementController. والفرع
            // الفارغ لا يقع إلا للسوبر أدمن، وهو يمرّ على `isSuperAdmin` أولاً.
            'canSettle' => Gate::allows('settle', [DeliveryProvider::class, (int) $scope['branchId']]),
            'expenseCategories' => ExpenseCategory::activeOptionsFor($scope['branchId']),
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
     * @param  Builder<ServiceInvoiceShipment>  $base
     * @return array<int, array<string, mixed>>
     */
    private function byProvider($base): array
    {
        return $base
            ->join('delivery_providers', 'delivery_providers.id', '=', 'service_invoice_shipments.provider_id')
            ->groupBy('delivery_providers.id', 'delivery_providers.name', 'delivery_providers.phone')
            ->orderByDesc('deliveries')
            ->get([
                'delivery_providers.id as provider_id',
                'delivery_providers.name as provider_name',
                'delivery_providers.phone as provider_phone',
                DB::raw('COUNT(*) as deliveries'),
                DB::raw('COALESCE(SUM(service_invoice_shipments.fee), 0) as fees'),
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
     * مزوّدو الفرع للفلتر — النشط منهم وغيره، فكشفُ الأمس يبقى مقروءاً بعد
     * تعطيل سائقٍ اليوم.
     *
     * @return Collection<int, DeliveryProvider>
     */
    private function providerOptions(?int $branchId): Collection
    {
        return DeliveryProvider::query()
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
