<?php

namespace App\Http\Controllers;

use App\Actions\Invoice\GenerateZatcaQrAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Http\Resources\Invoice\InvoiceListResource;
use App\Http\Resources\Invoice\InvoiceResource;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $isSuperAdmin = $user->roleName->isSuperAdmin();
        $branchId = $isSuperAdmin ? null : $user->branchId;

        $allowedTypes = $this->allowedTypesFor();

        // Honour an explicit type filter, but never beyond what the role allows.
        $requestedType = $request->input('type');
        $types = $requestedType && in_array($requestedType, InvoiceTypeEnum::all(), true)
            ? array_values(array_filter($allowedTypes, fn ($t) => $t->value === $requestedType))
            : $allowedTypes;

        $subQueries = array_map(
            fn (InvoiceTypeEnum $type) => $this->buildTypeQuery($type, $request, $isSuperAdmin, $branchId),
            $types,
        );

        if (empty($subQueries)) {
            $union = DB::table('product_invoices')->whereRaw('1 = 0')
                ->selectRaw('null as id, null as invoice_number, null as total_amount, null as status, null as created_at, null as type, null as customer_id, null as customer_name, null as customer_phone, null as customer_tax_number, null as employee_name, null as service_name, null as user_id, null as branch_name, null as cancellation_reason, null as delivery_at, null as paid_amount');
        } else {
            $union = array_shift($subQueries);
            foreach ($subQueries as $sub) {
                $union->unionAll($sub);
            }
        }

        $invoices = DB::query()
            ->fromSub($union, 'invoices')
            ->when($user->roleName->isEmployee(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('invoices/index', [
            'items' => InvoiceListResource::collection($invoices),
            'isSuperAdmin' => $isSuperAdmin,
            'availableTypes' => array_map(
                fn (InvoiceTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                $allowedTypes,
            ),
            // Only super-admins browse across branches, so only they get the picker.
            'branches' => $isSuperAdmin
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
            'filters' => $request->only(['search', 'type', 'status', 'date_from', 'date_to', 'branch_id', 'delivery']),
        ]);
    }

    public function show(string $type, int $id): Response
    {
        $invoice = $this->resolveInvoice($type, $id);
        Gate::authorize('view', $invoice);

        $invoice->load([
            'lines',
            'customer:id,full_name,phone,tax_number',
            'paymentMethod:id,name',
            'branch',
            'refunds' => fn ($q) => $q->with('user:id,name')->latest(),
            // بطاقة «الدفعات»: العربون وما تلاه، مع من سجّلها وبأي طريقة.
            // media يُحمَّل مسبقاً لأن receiptUrl() يقرأه لكل دفعة على حدة.
            'payments' => fn ($q) => $q->with(['paymentMethod:id,name', 'recordedBy:id,name', 'media'])->oldest('paid_at'),
        ]);

        // Product invoices carry a single agent on the row; service invoices list
        // several via the pivot, plus the per-line commission owners.
        if ($invoice instanceof ServiceInvoice) {
            $invoice->load('invoiceAgents.agent:id,name', 'lines.lineAgent:id,name', 'cancelledBy:id,name');
        } else {
            $invoice->load('agent:id,name');
        }

        return Inertia::render('invoices/show', [
            'invoice' => new InvoiceResource($invoice),
            // خيارات طريقة الدفع لنافذة «تسجيل دفعة» — دفعة واحدة قد تُقبض بطريقة
            // غير التي أُصدرت بها الفاتورة. requiresAttachment تُملي على النافذة
            // إظهار حقل الإيصال وفرضه.
            'paymentMethodOptions' => $invoice->branch
                ? $invoice->branch->enabledPaymentMethods()
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'requiresAttachment' => (bool) $m->requires_attachment])
                    ->values()
                : [],
        ]);
    }

    public function print(string $type, int $id, Request $request, GenerateZatcaQrAction $qrAction): Response
    {
        $invoice = $this->resolveInvoice($type, $id);
        Gate::authorize('view', $invoice);

        // الفواتير الملغاة لا تُطبع إطلاقاً
        abort_if($invoice->status === InvoiceStatusEnum::CANCELLED, 403, 'لا يمكن طباعة فاتورة ملغاة.');

        $invoice->load([
            'lines',
            'customer:id,full_name,phone,tax_number',
            'paymentMethod:id,name',
            'branch',
            // العربون والمتبقي يُطبعان أسفل الإجمالي.
            'payments' => fn ($q) => $q->with('paymentMethod:id,name')->oldest('paid_at'),
        ]);

        if ($invoice instanceof ServiceInvoice) {
            $invoice->load('invoiceAgents.agent:id,name', 'lines.lineAgent:id,name');
        } else {
            $invoice->load('agent:id,name');
        }

        $format = $request->input('format') === 'thermal' ? 'thermal' : 'a4';

        // الفاتورة التي لم يُقبض منها شيء تُطبع كعرض سعر: مستند غير ضريبي، فلا يُرسل
        // معه أيٌّ من مقوّمات الفاتورة الضريبية — لا الرقم الضريبي للفرع ولا رمز
        // الاستجابة الضريبي. العربون سداد، فالمدفوعة جزئياً تحملهما على كامل قيمتها.
        $isQuotation = ! $invoice->status->isTaxDocument();

        $payload = (new InvoiceResource($invoice))->toArray($request);

        if ($isQuotation) {
            $payload['branch']['taxNumber'] = null;
        }

        return Inertia::render('invoices/print', [
            'invoice' => $payload,
            'format' => $format,
            'zatcaQr' => $isQuotation ? null : $qrAction->handle($invoice),
        ]);
    }

    /**
     * Resolve {type}/{id} to the concrete invoice model, or 404.
     */
    private function resolveInvoice(string $type, int $id): ProductInvoice|ServiceInvoice
    {
        $enum = InvoiceTypeEnum::tryFrom($type);
        abort_if($enum === null, 404);

        return $enum->modelClass()::findOrFail($id);
    }

    /**
     * Invoice types the current user's role is permitted to browse,
     * mirroring ProductInvoicePolicy / ServiceInvoicePolicy.
     *
     * @return list<InvoiceTypeEnum>
     */
    private function allowedTypesFor(): array
    {
        $role = Auth::user()->roleName;

        $types = [];
        if ($role->isSuperAdmin() || $role->isBranchAdmin() || $role->isAccountant()) {
            $types[] = InvoiceTypeEnum::PRODUCT;
        }
        if ($role->isSuperAdmin() || $role->isBranchAdmin() || $role->isEmployee() || $role->isAccountant()) {
            $types[] = InvoiceTypeEnum::SERVICE;
        }

        return $types;
    }

    /**
     * Build a normalized sub-query for one invoice type, with filters applied.
     */
    private function buildTypeQuery(InvoiceTypeEnum $type, Request $request, bool $isSuperAdmin, ?int $branchId): Builder
    {
        $table = $type->table();

        // Service invoices carry the actual service names on their lines; surface
        // them (comma-joined, distinct) so the list can show "بحوث، تصميم…" instead
        // of the generic type label. Product rows have no equivalent.
        $serviceNameSelect = $type === InvoiceTypeEnum::SERVICE
            ? DB::raw("(select group_concat(distinct service_name) from service_invoice_lines where service_invoice_lines.invoice_id = {$table}.id) as service_name")
            : DB::raw('null as service_name');

        // Only service invoices can be cancelled by a reviewer, so the column
        // exists on that table alone; the product branch of the union pads it.
        $cancellationSelect = $type === InvoiceTypeEnum::SERVICE
            ? "{$table}.cancellation_reason"
            : DB::raw('null as cancellation_reason');

        // موعد التسليم كذلك خاص بفواتير الخدمات — فرع المنتجات من الاتحاد يحشوه.
        $deliverySelect = $type === InvoiceTypeEnum::SERVICE
            ? "{$table}.delivery_at"
            : DB::raw('null as delivery_at');

        // ما حُصِّل من الفاتورة عبر جدول الدفعات (عربون + دفعات لاحقة). الفاتورة
        // التي سُدِّدت عند البيع لا دفعات لها، فيُحسب عمود «المتبقي» في المورد من
        // الحالة نفسها. اسم الصنف كاملاً — لا morph map في المشروع، ويُربط
        // كمعامل لا كنص خام (الشرطة المائلة العكسية لا تُفلَت في SQLite).
        $paidSub = DB::table('invoice_payments')
            ->selectRaw('coalesce(sum(amount), 0)')
            ->where('invoice_payments.invoice_type', $type->modelClass())
            ->whereColumn('invoice_payments.invoice_id', "{$table}.id");

        $delivery = $request->input('delivery');

        return DB::table($table)
            ->leftJoin('customers', 'customers.id', '=', "{$table}.customer_id")
            ->leftJoin('users', 'users.id', '=', "{$table}.user_id")
            ->leftJoin('branches', 'branches.id', '=', "{$table}.branch_id")
            ->whereNull("{$table}.deleted_at")
            ->when(! $isSuperAdmin, fn ($q) => $q->where("{$table}.branch_id", $branchId))
            // Super-admins see every branch by default, and may narrow to one.
            ->when($isSuperAdmin && $request->filled('branch_id'),
                fn ($q) => $q->where("{$table}.branch_id", (int) $request->input('branch_id')))
            ->when($request->filled('status') && in_array($request->input('status'), InvoiceStatusEnum::all(), true),
                fn ($q) => $q->where("{$table}.status", $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate("{$table}.created_at", '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate("{$table}.created_at", '<=', $request->input('date_to')))
            // «تسليم اليوم / متأخر»: يخص فواتير الخدمات وحدها، فيُقصى فرع المنتجات
            // من الاتحاد كاملاً بدل أن يُرجع صفوفاً بلا موعد. الملغاة والمرتجعة لا
            // ينتظر أحد تسليمها — تماماً كما تقرّر DeliveryStatusEnum::forInvoice.
            ->when($delivery === 'today' || $delivery === 'overdue', function ($q) use ($table, $type, $delivery) {
                if ($type !== InvoiceTypeEnum::SERVICE) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereNotNull("{$table}.delivery_at")
                    ->whereNotIn("{$table}.status", [InvoiceStatusEnum::CANCELLED->value, InvoiceStatusEnum::RETURNED->value])
                    ->when(
                        $delivery === 'today',
                        fn ($q) => $q->whereDate("{$table}.delivery_at", today()),
                        fn ($q) => $q->whereDate("{$table}.delivery_at", '<', today()),
                    );
            })
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($table, $request) {
                $term = '%'.$request->input('search').'%';
                $q->where("{$table}.invoice_number", 'like', $term)
                    ->orWhere('users.name', 'like', $term);
            }))
            ->select([
                "{$table}.id",
                "{$table}.invoice_number",
                "{$table}.total_amount",
                "{$table}.status",
                "{$table}.created_at",
                DB::raw("'{$type->value}' as type"),
                'customers.id as customer_id',
                'customers.full_name as customer_name',
                'customers.phone as customer_phone',
                'customers.tax_number as customer_tax_number',
                'users.name as employee_name',
                $serviceNameSelect,
                "{$table}.user_id",
                'branches.name as branch_name',
                $cancellationSelect,
                $deliverySelect,
            ])
            ->selectSub($paidSub, 'paid_amount');
    }
}
