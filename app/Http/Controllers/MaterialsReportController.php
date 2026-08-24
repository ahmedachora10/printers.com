<?php

namespace App\Http\Controllers;

use App\Actions\Report\BuildReportDayRange;
use App\Actions\Report\ResolveReportScope;
use App\Enums\StockMovementTypeEnum;
use App\Exports\MaterialsReportExport;
use App\Http\Requests\Report\MaterialsReportFilterRequest;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Product;
use App\Models\ServiceInvoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تقرير استهلاك خامات الخدمات: ما سحبته الخدمات المعتمَدة من المخزون، وما أعادته
 * المرتجعاتُ والاسترجاعات إليه، وبكم.
 *
 * المصدر هو سجلّ المخزون نفسه لا وصفاتُ الخدمات: الحركة المكتوبة هي ما وقع فعلاً،
 * فوصفةٌ عُدّلت بعد البيع لا تعيد كتابة تاريخ الاستهلاك. ولهذا **الصافي هو مجموع
 * `qty` الموقَّع مقلوبَ الإشارة** — الصرف سالبٌ في السجلّ والإرجاع موجب، فتُطرح
 * المرتجعات وحدها دون حساب موازٍ. والتكلفة من `unit_cost` المخزَّن لحظةَ الحركة،
 * لا من سعر تكلفة المنتج اليوم.
 *
 * جمهوره وحدود فرعه نفس تقرير المصروفات، عبر ResolveReportScope.
 */
class MaterialsReportController extends Controller
{
    public function __construct(private readonly BuildReportDayRange $dayRange) {}

    public function index(MaterialsReportFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $this->scope($request, $resolveScope);

        return Inertia::render('reports/materials/index', [
            'totals' => $this->totals($scope),
            'byProduct' => $this->byProduct($scope),
            'byService' => $this->byService($scope),
            'byDay' => $this->byDay($scope),
            'movements' => $this->detailRows($scope),
            'filters' => [
                'from' => $scope['from']?->toDateString(),
                'to' => $scope['to']?->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'product' => $scope['productId'] ? (string) $scope['productId'] : null,
                'service' => $scope['serviceId'] ? (string) $scope['serviceId'] : null,
            ],
            'defaultDate' => Carbon::today()->toDateString(),
            'branches' => $scope['isSuper']
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'products' => $this->productOptions($scope),
            'services' => $this->serviceOptions($scope),
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(MaterialsReportFilterRequest $request, ResolveReportScope $resolveScope): BinaryFileResponse|HttpResponse
    {
        $scope = $this->scope($request, $resolveScope);

        return Excel::download(
            new MaterialsReportExport($this->detailRows($scope)),
            'materials-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * النطاق المشترك زائد مرشِّحَي هذا التقرير: خامةٌ بعينها أو خدمةٌ بعينها.
     *
     * @return array{isSuper: bool, branchId: ?int, productId: ?int, serviceId: ?int, from: Carbon, to: Carbon}
     */
    private function scope(MaterialsReportFilterRequest $request, ResolveReportScope $resolveScope): array
    {
        return [
            ...$resolveScope->handle($request),
            'productId' => $request->filled('product') ? (int) $request->input('product') : null,
            'serviceId' => $request->filled('service') ? (int) $request->input('service') : null,
        ];
    }

    /**
     * حركاتُ خامات فواتير الخدمات داخل النطاق — صرفاً وإرجاعاً.
     *
     * الوصلُ بسطر الخدمة يساري (LEFT): حركاتُ ما قبل إضافة العمود لا سطر لها،
     * وإسقاطُها كان سيُنقص التقرير صامتاً.
     *
     * @param  array<string, mixed>  $scope
     */
    private function baseQuery(array $scope): Builder
    {
        return DB::table('stock_movements')
            ->leftJoin('service_invoice_lines', 'service_invoice_lines.id', '=', 'stock_movements.service_invoice_line_id')
            ->where('stock_movements.reference_type', ServiceInvoice::class)
            ->whereIn('stock_movements.type', [
                StockMovementTypeEnum::SALE_OUT->value,
                StockMovementTypeEnum::RETURN_IN->value,
            ])
            ->when($scope['branchId'], fn ($q) => $q->where('stock_movements.branch_id', $scope['branchId']))
            ->when($scope['productId'], fn ($q) => $q->where('stock_movements.product_id', $scope['productId']))
            ->when($scope['serviceId'], fn ($q) => $q->where('service_invoice_lines.branch_service_id', $scope['serviceId']))
            ->when($scope['from'], fn ($q) => $q->where('stock_movements.created_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('stock_movements.created_at', '<=', $scope['to']));
    }

    /**
     * البطاقات: الكمية الصافية المستهلكة، وتكلفتها، وكم خامةً وكم فاتورةً وراءها.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, float|int>
     */
    private function totals(array $scope): array
    {
        $row = $this->baseQuery($scope)->first([
            DB::raw('COALESCE(SUM(-stock_movements.qty), 0) as net_qty'),
            DB::raw('COALESCE(SUM(-stock_movements.qty * COALESCE(stock_movements.unit_cost, 0)), 0) as net_cost'),
            DB::raw('COUNT(DISTINCT stock_movements.product_id) as products'),
            DB::raw('COUNT(DISTINCT stock_movements.reference_id) as invoices'),
        ]);

        return [
            'netQty' => round((float) $row->net_qty, 2),
            'netCost' => round((float) $row->net_cost, 2),
            'productCount' => (int) $row->products,
            'invoiceCount' => (int) $row->invoices,
        ];
    }

    /**
     * الاستهلاك لكل خامة، أغلاها أولاً.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byProduct(array $scope): array
    {
        return $this->baseQuery($scope)
            ->leftJoin('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('product_units', 'product_units.id', '=', 'products.unit_id')
            ->groupBy('stock_movements.product_id', 'products.name', 'products.is_sqm', 'product_units.name')
            ->orderByDesc(DB::raw('SUM(-stock_movements.qty * COALESCE(stock_movements.unit_cost, 0))'))
            ->get([
                'stock_movements.product_id as product_id',
                'products.name as product_name',
                'products.is_sqm as is_sqm',
                'product_units.name as unit_name',
                DB::raw('COALESCE(SUM(-stock_movements.qty), 0) as net_qty'),
                DB::raw('COALESCE(SUM(-stock_movements.qty * COALESCE(stock_movements.unit_cost, 0)), 0) as net_cost'),
            ])
            ->map(fn ($row) => [
                'productId' => (int) $row->product_id,
                'name' => $row->product_name ?? 'خامة محذوفة',
                'unitName' => $row->is_sqm ? 'متر مربع' : $row->unit_name,
                'netQty' => round((float) $row->net_qty, 2),
                'netCost' => round((float) $row->net_cost, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * الاستهلاك لكل خدمة. حركاتُ ما قبل إضافة عمود السطر لا خدمة لها، فتُجمع في
     * صفٍّ واحد صريح بدل أن تختفي من التقرير.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byService(array $scope): array
    {
        return $this->baseQuery($scope)
            ->groupBy('service_invoice_lines.branch_service_id', 'service_invoice_lines.service_name')
            ->orderByDesc(DB::raw('SUM(-stock_movements.qty * COALESCE(stock_movements.unit_cost, 0))'))
            ->get([
                'service_invoice_lines.branch_service_id as branch_service_id',
                'service_invoice_lines.service_name as service_name',
                DB::raw('COALESCE(SUM(-stock_movements.qty), 0) as net_qty'),
                DB::raw('COALESCE(SUM(-stock_movements.qty * COALESCE(stock_movements.unit_cost, 0)), 0) as net_cost'),
                DB::raw('COUNT(DISTINCT stock_movements.reference_id) as invoices'),
            ])
            ->map(fn ($row) => [
                'branchServiceId' => $row->branch_service_id !== null ? (int) $row->branch_service_id : null,
                'name' => $row->service_name ?? 'غير منسوبة لخدمة',
                'netQty' => round((float) $row->net_qty, 2),
                'netCost' => round((float) $row->net_cost, 2),
                'invoiceCount' => (int) $row->invoices,
            ])
            ->values()
            ->all();
    }

    /**
     * الاستهلاك لكل يوم، بأيامه الصامتة، فيقرأ الجدول تقويمه كاملاً.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function byDay(array $scope): array
    {
        $days = [];

        foreach ($this->dayRange->handle($scope) as $day) {
            $days[$day] = ['date' => $day, 'netQty' => 0.0, 'netCost' => 0.0];
        }

        $rows = $this->baseQuery($scope)
            ->groupBy(DB::raw('DATE(stock_movements.created_at)'))
            ->get([
                DB::raw('DATE(stock_movements.created_at) as day'),
                DB::raw('COALESCE(SUM(-stock_movements.qty), 0) as net_qty'),
                DB::raw('COALESCE(SUM(-stock_movements.qty * COALESCE(stock_movements.unit_cost, 0)), 0) as net_cost'),
            ]);

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $days[$day] = [
                'date' => $day,
                'netQty' => round((float) $row->net_qty, 2),
                'netCost' => round((float) $row->net_cost, 2),
            ];
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * التفصيل: حركة في كل صف، الأحدث أولاً. تغذّي الجدول والتصدير معاً فلا تختلف
     * الورقة عن الشاشة.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function detailRows(array $scope): Collection
    {
        return $this->baseQuery($scope)
            ->leftJoin('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('product_units', 'product_units.id', '=', 'products.unit_id')
            ->leftJoin('service_invoices', 'service_invoices.id', '=', 'stock_movements.reference_id')
            ->leftJoin('branches', 'branches.id', '=', 'stock_movements.branch_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.created_by')
            ->orderByDesc('stock_movements.created_at')
            ->orderByDesc('stock_movements.id')
            ->get([
                'stock_movements.id as id',
                'stock_movements.created_at as created_at',
                'stock_movements.type as type',
                'stock_movements.qty as qty',
                'stock_movements.unit_cost as unit_cost',
                'products.name as product_name',
                'products.is_sqm as is_sqm',
                'product_units.name as unit_name',
                'service_invoice_lines.service_name as service_name',
                'service_invoices.invoice_number as invoice_number',
                'service_invoices.id as invoice_id',
                'branches.name as branch_name',
                'users.name as user_name',
            ])
            ->map(function ($row) {
                $type = StockMovementTypeEnum::from((string) $row->type);
                $qty = (float) $row->qty;
                $unitCost = (float) $row->unit_cost;

                return [
                    'id' => (int) $row->id,
                    'date' => Carbon::parse($row->created_at)->toDateString(),
                    'direction' => $type->value,
                    'directionLabel' => $type === StockMovementTypeEnum::RETURN_IN ? 'إرجاع' : 'صرف',
                    'productName' => $row->product_name ?? 'خامة محذوفة',
                    'unitName' => $row->is_sqm ? 'متر مربع' : $row->unit_name,
                    // الكمية تُعرض موجبةً واتجاهُها في العمود المجاور — أما التكلفة
                    // فموقَّعة، فيقرأ مجموعُ العمود الصافيَ مباشرةً.
                    'qty' => round(abs($qty), 2),
                    'unitCost' => round($unitCost, 2),
                    'cost' => round(-$qty * $unitCost, 2),
                    'serviceName' => $row->service_name,
                    'invoiceId' => $row->invoice_id !== null ? (int) $row->invoice_id : null,
                    'invoiceNumber' => $row->invoice_number,
                    'branchName' => $row->branch_name,
                    'userName' => $row->user_name,
                ];
            })
            ->values();
    }

    /**
     * الخامات المعرَّفة على خدمات الفرع — قائمةُ المرشِّح، لا كلُّ منتجات المخزون.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function productOptions(array $scope): array
    {
        return Product::query()
            ->whereIn('id', DB::table('branch_service_materials')->select('product_id'))
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])
            ->all();
    }

    /**
     * الخدمات التي عُرِّفت لها خامات.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function serviceOptions(array $scope): array
    {
        return BranchService::query()
            ->whereIn('id', DB::table('branch_service_materials')->select('branch_service_id'))
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->with('serviceTemplate:id,name')
            ->get()
            ->map(fn (BranchService $s) => ['id' => $s->id, 'name' => $s->serviceTemplate?->name])
            ->filter(fn (array $s) => $s['name'] !== null)
            ->sortBy('name')
            ->values()
            ->all();
    }
}
