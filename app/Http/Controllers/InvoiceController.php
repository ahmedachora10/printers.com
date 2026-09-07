<?php

namespace App\Http\Controllers;

use App\Actions\Invoice\GenerateZatcaQrAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Http\Resources\Invoice\InvoiceListResource;
use App\Http\Resources\Invoice\InvoiceResource;
use App\Models\Branch;
use App\Models\PaymentMethod;
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
    /**
     * خيار الحالة الجامع «غير مسددة (عليها متبقٍ)» — ليس حالةً في
     * InvoiceStatusEnum بل جمعُ الآجلة والمدفوعة جزئياً، وهو ما يبحث عنه
     * المحاسب فعلاً حين يسأل عمّا لم يُحصَّل بعد (تاسك 92).
     */
    private const STATUS_UNSETTLED = 'unsettled';

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
                ->selectRaw('null as id, null as invoice_number, null as total_amount, null as status, null as created_at, null as type, null as customer_id, null as customer_name, null as customer_phone, null as customer_tax_number, null as employee_name, null as service_name, null as user_id, null as branch_name, null as cancellation_reason, null as delivery_at, null as delivered_at, null as payment_method_id, null as payment_method_name, null as payment_requires_attachment, null as paid_amount, null as refunded_amount, null as receipt_count');
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
            // خيارات الحالة من المصدر لا نسخةً يدوية في الواجهة — النسخة اليدوية
            // هي التي تخلّفت على «آجلة» بينما الخادم يسمّيها «غير مسددة» (تاسك 92).
            // ويتقدّمها خيارٌ جامع ليس حالةً في الـenum: كل ما على العميل متبقٍ.
            'statusOptions' => array_merge(
                [['value' => self::STATUS_UNSETTLED, 'label' => 'غير مسددة (عليها متبقٍ)']],
                array_map(
                    fn (InvoiceStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                    InvoiceStatusEnum::cases(),
                ),
            ),
            'filterOptions' => $this->filterOptions($isSuperAdmin, $branchId),
            'filters' => $request->only([
                'search', 'type', 'status', 'date_from', 'date_to', 'branch_id', 'delivery',
                'user_id', 'payment_method_id', 'branch_service_id',
            ]),
        ]);
    }

    /**
     * قوائم التصفية (تاسك 92): موظفو الفرع، وطرق الدفع، وخدمات الفرع. كلّها
     * مقيَّدة بفرع المستخدم ما لم يكن سوبر أدمن — وإلا رأى مدير الفرع أسماء
     * موظفي فرعٍ آخر في قائمته.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(bool $isSuperAdmin, ?int $branchId): array
    {
        $employees = DB::table('users')
            ->whereNull('users.deleted_at')
            ->when(! $isSuperAdmin, fn ($q) => $q->where('users.branch_id', $branchId))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        // الطرق العامة وما أضافه الفرع — نفس نطاق PaymentMethod::visibleToBranch
        // الذي تقرأه شاشة الفاتورة، فلا يفترق الفلتر عن مصدره.
        $paymentMethods = PaymentMethod::query()
            ->where('is_active', true)
            ->visibleToBranch($isSuperAdmin ? null : $branchId)
            ->orderBy('name')
            ->get(['id', 'name']);

        // الخدمة تُصفّى بمعرّف branch_service لا باسمها النصّي: الاسم لقطةٌ على
        // السطر وقد يتكرّر بين الفروع والقوالب.
        $services = DB::table('branch_services')
            ->join('service_templates', 'service_templates.id', '=', 'branch_services.service_template_id')
            ->when(! $isSuperAdmin, fn ($q) => $q->where('branch_services.branch_id', $branchId))
            ->orderBy('service_templates.name')
            ->get(['branch_services.id', 'service_templates.name']);

        return [
            'employees' => $employees,
            'paymentMethods' => $paymentMethods,
            'services' => $services,
        ];
    }

    public function show(string $type, int $id): Response
    {
        $invoice = $this->resolveInvoice($type, $id);
        Gate::authorize('view', $invoice);

        $invoice->load([
            'lines',
            'customer:id,full_name,phone,tax_number',
            'user:id,name',
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
            $invoice->load('invoiceAgents.agent:id,name', 'lines.lineAgent:id,name', 'cancelledBy:id,name', 'deliveredBy:id,name');
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
            'user:id,name',
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

        // تاسك 94: أرقام التكلفة الداخلية (تكلفة الخامات، عمولة السطر، الشريحة)
        // لا تُطبع للعميل بحال ولأي دور — ولا يكفي إخفاؤها في المكوّن، فحمولة
        // Inertia تصل المتصفح كاملةً ويقرؤها من يفتح مصدر الصفحة. تُحجب على
        // الخادم كما يُحجب الرقم الضريبي في عرض السعر.
        $payload = (new InvoiceResource($invoice))->withoutInternalCosts()->toArray($request);

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

        // موعد التسليم وختم التسليم الفعلي كلاهما خاص بفواتير الخدمات — فرع
        // المنتجات من الاتحاد يحشوهما.
        $deliverySelect = $type === InvoiceTypeEnum::SERVICE
            ? "{$table}.delivery_at"
            : DB::raw('null as delivery_at');

        $deliveredSelect = $type === InvoiceTypeEnum::SERVICE
            ? "{$table}.delivered_at"
            : DB::raw('null as delivered_at');

        // ما حُصِّل من الفاتورة عبر جدول الدفعات (عربون + دفعات لاحقة). الفاتورة
        // التي سُدِّدت عند البيع لا دفعات لها، فيُحسب عمود «المتبقي» في المورد من
        // الحالة نفسها. اسم الصنف كاملاً — لا morph map في المشروع، ويُربط
        // كمعامل لا كنص خام (الشرطة المائلة العكسية لا تُفلَت في SQLite).
        $paidSub = DB::table('invoice_payments')
            ->selectRaw('coalesce(sum(amount), 0)')
            ->where('invoice_payments.invoice_type', $type->modelClass())
            ->whereColumn('invoice_payments.invoice_id', "{$table}.id");

        // ما استُرجع من الفاتورة. المرتجع الجزئي لا يغيّر الحالة — الفاتورة تبقى
        // مدفوعة ومحتسبة في المبيعات — فهذا العمود هو ما يُظهر للمستخدم أنّ عليها
        // مرتجعاً، ويقابله وسم «مرتجع جزئي» في القائمة. المرتجع الكامل يقلب الحالة
        // إلى «مرتجع» فيغني عن الوسم.
        $refundedSub = DB::table('refunds')
            ->selectRaw('coalesce(sum(amount), 0)')
            ->where('refunds.invoice_type', $type->modelClass())
            ->whereColumn('refunds.invoice_id', "{$table}.id")
            ->whereNull('refunds.deleted_at');

        // إيصال التحويل يُرفق كوسائط على الفاتورة نفسها (HasReceiptMedia). عدُّه
        // هنا يُغني صفَّ القائمة عن تحميل الوسائط لكل فاتورة، ويسمح لزرّ الاعتماد
        // السريع بمعرفة الناقص قبل أن يُرسل طلباً يُرفض.
        $receiptSub = DB::table('media')
            ->selectRaw('count(*)')
            ->where('media.model_type', $type->modelClass())
            ->where('media.collection_name', 'receipt')
            ->whereColumn('media.model_id', "{$table}.id");

        $delivery = $request->input('delivery');

        return DB::table($table)
            ->leftJoin('customers', 'customers.id', '=', "{$table}.customer_id")
            ->leftJoin('users', 'users.id', '=', "{$table}.user_id")
            ->leftJoin('branches', 'branches.id', '=', "{$table}.branch_id")
            // طريقة الدفع عمودٌ على كلا الجدولين، فالوصلة واحدة لفرعَي الاتحاد.
            // تُعرض في القائمة (تاسك 88) ويُصفّى بها (تاسك 92).
            ->leftJoin('payment_methods', 'payment_methods.id', '=', "{$table}.payment_method_id")
            ->whereNull("{$table}.deleted_at")
            ->when(! $isSuperAdmin, fn ($q) => $q->where("{$table}.branch_id", $branchId))
            // Super-admins see every branch by default, and may narrow to one.
            ->when($isSuperAdmin && $request->filled('branch_id'),
                fn ($q) => $q->where("{$table}.branch_id", (int) $request->input('branch_id')))
            ->when($request->filled('status') && in_array($request->input('status'), InvoiceStatusEnum::all(), true),
                fn ($q) => $q->where("{$table}.status", $request->input('status')))
            // «غير مسددة (عليها متبقٍ)»: خيارٌ جامع لا حالةٌ في الـenum.
            ->when($request->input('status') === self::STATUS_UNSETTLED, fn ($q) => $q->whereIn("{$table}.status", [
                InvoiceStatusEnum::DUE->value,
                InvoiceStatusEnum::PARTIALLY_PAID->value,
            ]))
            // منشئ الفاتورة — فلترٌ صريح بدل البحث النصّي في اسم الموظف.
            ->when($request->filled('user_id'), fn ($q) => $q->where("{$table}.user_id", (int) $request->input('user_id')))
            ->when($request->filled('payment_method_id'),
                fn ($q) => $q->where("{$table}.payment_method_id", (int) $request->input('payment_method_id')))
            // نوع الخدمة يخصّ فواتير الخدمات وحدها، فاختياره يُقصي فرع المنتجات
            // من الاتحاد كاملاً — تماماً كما يفعل فلتر موعد التسليم أدناه.
            ->when($request->filled('branch_service_id'), function ($q) use ($table, $type, $request) {
                if ($type !== InvoiceTypeEnum::SERVICE) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereExists(fn ($sub) => $sub->from('service_invoice_lines')
                    ->whereColumn('service_invoice_lines.invoice_id', "{$table}.id")
                    ->where('service_invoice_lines.branch_service_id', (int) $request->input('branch_service_id')));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate("{$table}.created_at", '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate("{$table}.created_at", '<=', $request->input('date_to')))
            // «تسليم اليوم / متأخر / تم التسليم»: يخص فواتير الخدمات وحدها، فيُقصى
            // فرع المنتجات من الاتحاد كاملاً بدل أن يُرجع صفوفاً بلا موعد. الملغاة
            // والمرتجعة لا ينتظر أحد تسليمها، والمُسلَّمة تغادر «اليوم» و«المتأخر»
            // إلى خانتها — تماماً كما تقرّر DeliveryStatusEnum::forInvoice.
            ->when(in_array($delivery, ['today', 'overdue', 'delivered'], true), function ($q) use ($table, $type, $delivery) {
                if ($type !== InvoiceTypeEnum::SERVICE) {
                    return $q->whereRaw('1 = 0');
                }

                $q->whereNotIn("{$table}.status", [InvoiceStatusEnum::CANCELLED->value, InvoiceStatusEnum::RETURNED->value]);

                if ($delivery === 'delivered') {
                    return $q->whereNotNull("{$table}.delivered_at");
                }

                return $q->whereNotNull("{$table}.delivery_at")
                    ->whereNull("{$table}.delivered_at")
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
                $deliveredSelect,
                "{$table}.payment_method_id",
                'payment_methods.name as payment_method_name',
                'payment_methods.requires_attachment as payment_requires_attachment',
            ])
            ->selectSub($paidSub, 'paid_amount')
            ->selectSub($refundedSub, 'refunded_amount')
            ->selectSub($receiptSub, 'receipt_count');
    }
}
