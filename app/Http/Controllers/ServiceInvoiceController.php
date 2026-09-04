<?php

namespace App\Http\Controllers;

use App\Actions\Agent\ListBranchAgentsAction;
use App\Actions\Customer\UpdateCustomerAction;
use App\Actions\Loyalty\ResolveAvailablePointsAction;
use App\Actions\ServiceInvoice\AttachServiceInvoiceCustomerAction;
use App\Actions\ServiceInvoice\CalculateServiceInvoiceAction;
use App\Actions\ServiceInvoice\CancelServiceInvoiceAction;
use App\Actions\ServiceInvoice\CreateServiceInvoiceAction;
use App\Actions\ServiceInvoice\MarkServiceInvoiceDeliveredAction;
use App\Actions\ServiceInvoice\MarkServiceInvoicePaidAction;
use App\Actions\ServiceInvoice\ReturnServiceInvoiceAction;
use App\Actions\ServiceInvoice\UpdateServiceInvoiceAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Enums\Roles;
use App\Http\Requests\ServiceInvoice\CancelServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\ReturnServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\ReviewQueueFilterRequest;
use App\Http\Requests\ServiceInvoice\StoreServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\UpdateInvoiceCustomerRequest;
use App\Http\Requests\ServiceInvoice\UpdateInvoicePaymentMethodRequest;
use App\Http\Requests\ServiceInvoice\UpdateServiceInvoiceRequest;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\BranchServiceMaterial;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Models\UserFavoriteService;
use App\Models\UserService;
use App\Notifications\DueInvoiceNotification;
use App\Notifications\ServiceInvoiceReviewedNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ServiceInvoiceController extends Controller
{
    public function create(ListBranchAgentsAction $listBranchAgents): Response
    {
        Gate::authorize('create', ServiceInvoice::class);

        return Inertia::render('pos/service/index', $this->posFormData(Auth::user(), $listBranchAgents));
    }

    /**
     * Re-open a DUE invoice in the POS form (before an accountant approves it):
     * for its owning employee, or for a reviewer in its branch correcting it —
     * the materials cost above all, which the employee may not touch (تاسك 70).
     * The form is seeded from the invoice.
     */
    public function edit(ServiceInvoice $invoice, ListBranchAgentsAction $listBranchAgents, ResolveAvailablePointsAction $availablePoints): Response
    {
        Gate::authorize('update', $invoice);

        $user = Auth::user();
        $branchId = (int) $invoice->branch_id;

        $invoice->load(['lines', 'user:id,name', 'customer:id,full_name,phone,tax_number,agent_id,customer_type,points_balance,tier', 'invoiceAgents:id,service_invoice_id,agent_id']);

        $loyalty = LoyaltyConfig::forBranch($branchId);
        $loyaltyActive = (bool) $loyalty->is_active;

        // العمولات تُقرأ بـ**موظف الفاتورة** لا بمن يعدّلها: المراجع لا يملك
        // صفوف user_services أصلاً، فلو قُرئت به لظهرت كل النسب صفراً على الشاشة
        // بينما يحسب الخادم عمولة الموظف الحقيقية عند الحفظ.
        $servicesById = $this->branchServiceOptions($branchId, (int) $invoice->user_id, $user->id)->keyBy('id');

        $coupon = $invoice->coupon_id ? Coupon::find($invoice->coupon_id) : null;

        return Inertia::render('pos/service/index', [
            ...$this->posFormData($user, $listBranchAgents, $invoice),
            'invoice' => [
                'id' => $invoice->id,
                'invoiceNumber' => $invoice->invoice_number,
                // صاحب الفاتورة — تعرضه اللافتة حين يفتحها غيرُه (تاسك 70).
                'employeeName' => $invoice->user?->name,
                'isOwn' => $user->id === (int) $invoice->user_id,
                // حجز هذه الفاتورة نفسها لا يُطرح من رصيد عميلها هنا: نقاطها لها،
                // فإعادة إرسال العدد نفسه لا تُرفض ولا يظهر الرصيد منقوصاً مرتين.
                'customer' => $invoice->customer?->toPosArray(
                    $loyalty,
                    $loyaltyActive,
                    $invoice->customer_id !== null ? $availablePoints->reserved((int) $invoice->customer_id, $invoice) : 0,
                ),
                'agentIds' => $invoice->invoiceAgents->pluck('agent_id')->values(),
                'coupon' => $coupon ? [
                    'code' => $coupon->code,
                    'type' => $coupon->discount_type->value,
                    'value' => (float) $coupon->discount_value,
                ] : null,
                'pointsRedeemed' => (int) $invoice->points_redeemed,
                'paymentMethodId' => $invoice->payment_method_id,
                'hasReceipt' => $invoice->hasReceipt(),
                'notes' => $invoice->notes,
                // «YYYY-MM-DD HH:MM» — الصيغة التي يقرأها منتقي الموعد في الواجهة.
                'deliveryAt' => $invoice->delivery_at?->format('Y-m-d H:i'),
                'lines' => $invoice->lines->map(function ($line) use ($servicesById) {
                    $service = $servicesById->get($line->branch_service_id);

                    return [
                        'branchServiceId' => $line->branch_service_id,
                        'name' => $line->service_name,
                        'notes' => $line->notes,
                        'qty' => $line->qty,
                        // سطر المتر يعود إلى نقطة البيع بسعر المتر — والسطر القديم
                        // الذي حُفظ بسعر القطعة يُقسم على مساحته أولاً فلا يتغيّر
                        // إجماليه لمجرد إعادة حفظه.
                        'unitPrice' => ($service['pricingType'] ?? 'unit') === 'sqm'
                            ? $line->unitPricePerSqm()
                            : (float) $line->unit_price,
                        'discountPct' => (float) $line->discount_pct,
                        'maxDiscountPct' => (float) ($service['maxDiscountPct'] ?? 0),
                        'maxSellingPrice' => $service['maxSellingPrice'] ?? null,
                        'minSellingPrice' => $service['minSellingPrice'] ?? null,
                        'baseCommissionPct' => (float) ($service['baseCommissionPct'] ?? $line->commission_pct),
                        'isTahazir' => (bool) ($service['isTahazir'] ?? false),
                        'pricingType' => $service['pricingType'] ?? 'unit',
                        'pricePerSqm' => (float) ($service['pricePerSqm'] ?? 0),
                        'agentCommissionPerSqm' => (float) ($service['agentCommissionPerSqm'] ?? 0),
                        'noteExamples' => $service['noteExamples'] ?? [],
                        // الخامات تأتي من السطر المحفوظ لا من الخدمة: اللقطة هي
                        // الحقيقة عند التعديل، فقد عُدّل المبلغ وقت الفوترة.
                        'hasMaterials' => (float) $line->materials_cost > 0,
                        'materialsCost' => (float) $line->materials_cost,
                        'materialsCostIsOpen' => (bool) ($service['materialsCostIsOpen'] ?? false),
                        'widthCm' => $line->width_cm !== null ? (float) $line->width_cm : null,
                        'heightCm' => $line->height_cm !== null ? (float) $line->height_cm : null,
                        'agentId' => $line->agent_id,
                        'agentCommissionType' => $line->agent_commission_type?->value,
                        'agentCommissionValue' => $line->agent_commission_value !== null ? (float) $line->agent_commission_value : null,
                    ];
                })->values(),
            ],
        ]);
    }

    public function store(StoreServiceInvoiceRequest $request, CreateServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('create', ServiceInvoice::class);

        $invoice = $action->handle($request->validated(), $request->file('receipt'));

        if ($invoice->status === InvoiceStatusEnum::DUE) {
            Notification::send(
                BranchNotifiables::forBranch($invoice->branch_id, [Roles::BRANCH_ADMIN->value, Roles::ACCOUNTANT->value]),
                new DueInvoiceNotification($invoice->invoice_number, $invoice->id, InvoiceTypeEnum::SERVICE, (float) $invoice->total_amount),
            );
        }

        if ($request->boolean('print')) {
            return to_route('pos.service.print', $invoice)
                ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
        }

        return to_route('pos.service.create')
            ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
    }

    /**
     * Persist an in-place edit of a DUE invoice — by its owning employee, or by
     * a reviewer in its branch (تاسك 70) — then return to the invoice viewer.
     * The invoice stays DUE either way: editing never approves it.
     */
    public function update(UpdateServiceInvoiceRequest $request, ServiceInvoice $invoice, UpdateServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('update', $invoice);

        $action->handle($invoice, $request->validated(), $request->file('receipt'));

        return to_route('invoices.show', ['type' => InvoiceTypeEnum::SERVICE->value, 'id' => $invoice->id])
            ->with('success', "تم تحديث الفاتورة {$invoice->invoice_number} بنجاح");
    }

    /**
     * Return (استرجاع) an employee's own invoice, before or after approval: the
     * invoice keeps its row and moves to RETURNED while its accruals are unwound
     * and — for a settled invoice — an M14 refund is booked. Only the invoice's
     * owner may do this, never an accountant.
     */
    public function returnInvoice(ReturnServiceInvoiceRequest $request, ServiceInvoice $invoice, ReturnServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('returnInvoice', $invoice);

        $action->handle($invoice, Auth::user(), $request->validated('reason'));

        return to_route('invoices.index')
            ->with('success', "تم استرجاع الفاتورة {$invoice->invoice_number} بنجاح");
    }

    /**
     * ختم «تم تسليم العمل» (تاسك 31) — يُترك المستخدم حيث هو، فالزر يُضغط من
     * قائمة الفواتير كما يُضغط من صفحة الفاتورة.
     */
    public function deliver(ServiceInvoice $invoice, MarkServiceInvoiceDeliveredAction $action): RedirectResponse
    {
        Gate::authorize('deliver', $invoice);

        $action->handle($invoice, Auth::user());

        return back(fallback: route('invoices.index'))
            ->with('success', "تم تسليم عمل الفاتورة {$invoice->invoice_number}");
    }

    /**
     * Review queue of due service invoices awaiting an accountant/branch-admin
     * decision (settle or cancel). Super admins see every branch.
     */
    public function review(ReviewQueueFilterRequest $request): Response
    {
        Gate::authorize('review', ServiceInvoice::class);

        $user = Auth::user();
        $isSuperAdmin = $user->roleName->isSuperAdmin();

        // عروض الأسعار وحدها: الفاتورة التي قُبض عليها عربون لم تعد عرض سعر، فتغادر
        // الطابور فور أول دفعة ويُستكمل سدادها من صفحتها.
        $query = ServiceInvoice::query()
            ->where('status', InvoiceStatusEnum::DUE)
            ->when(! $isSuperAdmin, fn ($q) => $q->where('branch_id', $user->branchId))
            // تاسك 60: مدى تاريخي على يوم التحرير + بحث + فرز + تصفّح. القائمة
            // كانت تُجلب كاملةً بـget() فتكبر بلا حدّ مع الوقت.
            ->when($request->date('from'), fn ($q, $from) => $q->where('service_invoices.created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('service_invoices.created_at', '<=', $to->endOfDay()))
            ->when($request->string('search')->trim()->value(), fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('invoice_number', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', '%'.$search.'%'))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$search.'%'))));

        // ملخّص **المدى كله** لا الصفحة المعروضة، من نسخة سابقة للتصفّح.
        $summary = (clone $query)
            ->selectRaw('COUNT(*) as quotes_count, COALESCE(SUM(total_amount), 0) as quotes_total')
            ->first();

        $paginator = $query
            ->with(['lines', 'customer:id,full_name,phone,tax_number', 'user:id,name', 'branch:id,name', 'paymentMethod:id,name', 'media'])
            ->orderBy($request->sortColumn(), $request->sortDirection())
            // فارز ثانوي ثابت: صفّان بنفس اللحظة كانا يتبادلان الترتيب بين صفحة
            // وأخرى فيتكرّر أحدهما ويسقط الآخر.
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $dueInvoices = $paginator->getCollection();

        // The payment-method options a reviewer may switch to depend on the
        // invoice's branch (super admins see several). Resolve each branch's
        // enabled methods once, then reuse per invoice. Built from the **current
        // page** only — that is all the page renders, and a super admin paging
        // into another branch simply resolves that branch on the next request.
        $methodsByBranch = $dueInvoices->pluck('branch_id')->unique()->mapWithKeys(function ($branchId) {
            $branch = Branch::find($branchId);

            return [$branchId => $branch
                ? $branch->enabledPaymentMethods()
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'requiresAttachment' => (bool) $m->requires_attachment])
                    ->values()
                : collect()];
        });

        $invoices = $dueInvoices
            ->map(function (ServiceInvoice $invoice) use ($methodsByBranch) {
                $options = collect($methodsByBranch[$invoice->branch_id] ?? []);

                // Keep the current method selectable even if it was later disabled.
                if ($invoice->paymentMethod && ! $options->contains('id', $invoice->payment_method_id)) {
                    $options = $options->prepend([
                        'id' => $invoice->payment_method_id,
                        'name' => $invoice->paymentMethod->name,
                        'requiresAttachment' => (bool) $invoice->paymentMethod->requires_attachment,
                    ]);
                }

                return [
                    'id' => $invoice->id,
                    'invoiceNumber' => $invoice->invoice_number,
                    'createdAt' => $invoice->created_at?->toIso8601String(),
                    'employeeName' => $invoice->user?->name,
                    'customerId' => $invoice->customer?->id,
                    'customerName' => $invoice->customer?->full_name,
                    'customerPhone' => $invoice->customer?->phone,
                    'customerTaxNumber' => $invoice->customer?->tax_number,
                    'branchName' => $invoice->branch?->name,
                    'paymentMethod' => $invoice->paymentMethod?->name,
                    'paymentMethodId' => $invoice->payment_method_id,
                    'paymentMethodOptions' => $options->values(),
                    'receiptUrl' => $invoice->receiptUrl(),
                    'subtotal' => (float) $invoice->subtotal,
                    'vatAmount' => (float) $invoice->vat_amount,
                    'totalAmount' => (float) $invoice->total_amount,
                    // سقف الدفعة الأولى (العربون). الطابور لا يحمل إلا فواتير آجلة
                    // لم يُقبض منها شيء، فالمتبقي هو الإجمالي — ويُرسل صراحةً لأن
                    // نافذة تسجيل الدفعة تحدّ به المبلغ.
                    'remainingAmount' => $invoice->remainingAmount(),
                    // زرّ تعديل الفاتورة في الطابور: لمدير الفرع لا للمحاسب —
                    // الصلاحية هي الفيصل، فلا يُكرَّر الدور في الواجهة.
                    'canEdit' => Gate::allows('update', $invoice),
                    'lines' => $invoice->lines->map(fn ($line) => [
                        'name' => $line->service_name,
                        'notes' => $line->notes,
                        'qty' => $line->qty,
                        'unitPrice' => (float) $line->unit_price,
                        'unitPriceBasis' => $line->isPricedPerSqm() ? 'sqm' : null,
                        'widthCm' => $line->width_cm !== null ? (float) $line->width_cm : null,
                        'heightCm' => $line->height_cm !== null ? (float) $line->height_cm : null,
                        'discountPct' => (float) $line->discount_pct,
                        'subtotal' => (float) $line->subtotal,
                    ])->values(),
                ];
            });

        return Inertia::render('invoices/review', [
            // تبقى مصفوفةً مسطّحة (صفحة واحدة) وبيانات التصفّح بجانبها، فلا تتغيّر
            // قراءة الصفحة لكل عرض.
            'invoices' => $invoices->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            // شارة العدد تتبع المدى المطبَّق لا كل الطابور.
            'summary' => [
                'quotesCount' => (int) ($summary->quotes_count ?? 0),
                'quotesTotal' => round((float) ($summary->quotes_total ?? 0), 2),
            ],
            'filters' => [
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'search' => $request->input('search'),
                'sort' => $request->sortColumn(),
                'dir' => $request->sortDirection(),
            ],
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function markPaid(Request $request, ServiceInvoice $invoice, MarkServiceInvoicePaidAction $action): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        // فاتورة قُبض عليها عربون تُغلق بتسجيل دفعة المتبقي، لا باعتماد مجمل — وإلا
        // صارت «مدفوعة» ومجموع دفعاتها أقل من إجمالها.
        if ($invoice->status === InvoiceStatusEnum::PARTIALLY_PAID) {
            throw ValidationException::withMessages([
                'status' => 'الفاتورة مدفوعة جزئياً — سجّل دفعة بالمتبقي ('.number_format($invoice->remainingAmount(), 2).' ر.س) لإغلاقها.',
            ]);
        }

        $this->assertPaymentMethodChosen($invoice);
        $this->assertReceiptAttached($invoice);

        // العجز في خامات المخزون يوقف الاعتماد مرةً واحدة ويعرض الناقص؛ فإن أقرّه
        // المعتمِد أعاد الإرسال بهذا المفتاح فيمرّ، ويُسجَّل إقراره في سجلّ النشاط.
        $action->handle($invoice, null, $request->boolean('confirm_materials_shortage'));

        Notification::send(
            $this->reviewNotifiables($invoice),
            new ServiceInvoiceReviewedNotification($invoice->invoice_number, $invoice->id, (float) $invoice->total_amount, InvoiceStatusEnum::PAID),
        );

        // Redirect back so approving from the invoice viewer returns to the
        // (now paid) invoice; from the review queue it falls back there too.
        return back()->with('success', "تم اعتماد دفع الفاتورة {$invoice->invoice_number}");
    }

    /**
     * تاسك 59 — لا تُعتمد فاتورة بلا طريقة دفع.
     *
     * كانت تُعتمد وطريقة دفعها «—»، فيخرج تقرير المبيعات بمبلغ لا ينسبه إلى نقد
     * ولا شبكة ولا تحويل. الفحص على **الخادم** لأن تعطيل الزرّ وحده يُلتفّ عليه
     * بطلب مباشر. ويُشترط أن تكون الطريقة ضمن طرق الفرع المفعّلة، وإلا اعتُمدت
     * فاتورة بطريقة عطّلها الفرع أو تخصّ فرعاً آخر.
     */
    private function assertPaymentMethodChosen(ServiceInvoice $invoice): void
    {
        if ($invoice->payment_method_id === null) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'حدّد طريقة الدفع قبل اعتماد الفاتورة.',
            ]);
        }

        $allowed = $invoice->branch?->enabledPaymentMethods() ?? collect();

        if (! $allowed->contains('id', $invoice->payment_method_id)) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'طريقة الدفع المحددة غير متاحة لهذا الفرع — اختر طريقة أخرى قبل الاعتماد.',
            ]);
        }
    }

    /**
     * لا تُعتمد فاتورةُ تحويلٍ بلا إثبات تحويلها.
     *
     * الشبكة الأمانية الأخيرة: نموذجُ تعديل الطريقة يفرض الإيصال ساعةَ اختيارها،
     * لكن تبقى فواتيرُ أُنشئت قبل تفعيل العَلَم على الطريقة، وتبقى الطلباتُ التي
     * تتخطّى الواجهة. والمعتمِد يُرفق الإيصال من نفس الشاشة ثم يعتمد.
     */
    private function assertReceiptAttached(ServiceInvoice $invoice): void
    {
        $method = $invoice->paymentMethod;

        if ($method?->requires_attachment && ! $invoice->hasReceipt()) {
            throw ValidationException::withMessages([
                'receipt' => "طريقة الدفع «{$method->name}» تستلزم إيصال التحويل — أرفقه قبل اعتماد الفاتورة.",
            ]);
        }
    }

    /**
     * Set the invoice's customer, from the accountant's review queue or from the
     * owning employee's POS edit screen. For a linked customer this corrects the
     * shared record's name/phone/tax number (affects the customer everywhere);
     * for a due invoice with no customer (walk-in), it registers/links one by phone.
     */
    public function updateCustomer(
        UpdateInvoiceCustomerRequest $request,
        ServiceInvoice $invoice,
        UpdateCustomerAction $updateAction,
        AttachServiceInvoiceCustomerAction $attachAction,
    ): RedirectResponse {
        Gate::authorize('updateCustomer', $invoice);

        $customer = $invoice->customer;

        if ($customer === null) {
            // من الفاتورة لا من السجلّ: المحاسب يسجّل عميل الفاتورة التي يراجعها
            // وإن كان ممنوعاً من شاشة العملاء (تاسك 40).
            Gate::authorize('createFromInvoice', Customer::class);

            $attachAction->handle($invoice, $request->validated());

            return redirect()->back(fallback: $this->customerEditFallback($invoice))
                ->with('success', "تم إضافة بيانات العميل للفاتورة {$invoice->invoice_number}");
        }

        // Reported inline rather than as a 403: the employee is editing a form,
        // and a full error page in place of a field message is no help to them.
        if (! Gate::allows('updateFromInvoice', $customer)) {
            throw ValidationException::withMessages([
                'full_name' => 'هذا العميل مرتبط بشركة أو مندوب — راجع المحاسب لتعديل بياناته.',
            ]);
        }

        $updateAction->handle($customer, $request->validated());

        return redirect()->back(fallback: $this->customerEditFallback($invoice))
            ->with('success', "تم تحديث بيانات العميل للفاتورة {$invoice->invoice_number}");
    }

    /**
     * Where to land after a customer edit when the request carries no referer:
     * back to the queue for whoever reviews invoices, or the invoice list for
     * the employee who edited their own.
     */
    private function customerEditFallback(ServiceInvoice $invoice): string
    {
        return Gate::allows('updateStatus', $invoice)
            ? route('invoices.service.review')
            : route('invoices.index');
    }

    /**
     * Correct the payment method (transfer, card, mada, …) of a due invoice —
     * from the review queue or from the invoice itself, which is the accountant's
     * only way to name it now that the POS edit screen is closed to him. Hence
     * back() rather than a fixed redirect: whoever asked stays where they were.
     * Restricted to the branch's enabled methods by the request.
     */
    public function updatePaymentMethod(UpdateInvoicePaymentMethodRequest $request, ServiceInvoice $invoice): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        // الطريقة وإيصالها يُحفظان معاً أو لا يُحفظ أيّهما: طريقةٌ تشترط مرفقاً
        // حُفظت بلا مرفقه تترك الفاتورة في الحال الذي مُنع أصلاً.
        DB::transaction(function () use ($request, $invoice) {
            $invoice->update(['payment_method_id' => $request->validated('payment_method_id')]);

            if ($request->hasFile('receipt')) {
                $invoice->addMedia($request->file('receipt'))
                    ->toMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
            }
        });

        return redirect()->back(fallback: route('invoices.service.review'))
            ->with('success', "تم تحديث طريقة الدفع للفاتورة {$invoice->invoice_number}");
    }

    public function cancel(CancelServiceInvoiceRequest $request, ServiceInvoice $invoice, CancelServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        $reason = $request->validated()['reason'];
        $action->handle($invoice, $reason, Auth::user());

        Notification::send(
            $this->reviewNotifiables($invoice),
            new ServiceInvoiceReviewedNotification($invoice->invoice_number, $invoice->id, (float) $invoice->total_amount, InvoiceStatusEnum::CANCELLED, $reason),
        );

        return to_route('invoices.service.review')
            ->with('success', "تم إلغاء الفاتورة {$invoice->invoice_number}");
    }

    /**
     * Who to notify of a review decision: the employee who raised the invoice,
     * the branch admin, and super admins — excluding whoever made the decision.
     *
     * @return Collection<int, User>
     */
    private function reviewNotifiables(ServiceInvoice $invoice): Collection
    {
        $recipients = collect();

        if ($invoice->user) {
            $recipients->push($invoice->user);
        }

        $recipients = $recipients
            ->concat(BranchNotifiables::forBranch($invoice->branch_id, [Roles::BRANCH_ADMIN->value]))
            ->concat(User::query()->whereHas('roles', fn ($q) => $q->where('name', Roles::SUPER_ADMIN->value))->get());

        return $recipients->unique('id')->reject(fn (User $u) => $u->id === Auth::id())->values();
    }

    public function print(ServiceInvoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'lines',
            'customer:id,full_name,phone,tax_number',
            'paymentMethod:id,name',
            'branch:id,name,phone,address,tax_number',
            'payments',
        ]);

        // ما لم يُقبض منه شيء ورقتُه عرض سعر لا فاتورة ضريبية — فلا تحمل الرقم
        // الضريبي للفرع. والعربون سداد، فالمدفوعة جزئياً فاتورة ضريبية.
        $isQuotation = ! $invoice->status->isTaxDocument();

        return Inertia::render('pos/service/print', [
            'invoice' => [
                'invoiceNumber' => $invoice->invoice_number,
                'createdAt' => $invoice->created_at?->toIso8601String(),
                'status' => $invoice->status->value,
                'statusLabel' => $invoice->status->label(),
                'subtotal' => (float) $invoice->subtotal,
                'tierDiscountAmount' => (float) $invoice->tier_discount_amount,
                'couponDiscount' => (float) $invoice->coupon_discount,
                'agentDiscount' => (float) $invoice->agent_discount,
                'pointsDiscount' => (float) $invoice->points_discount,
                'vatPct' => (float) $invoice->vat_pct,
                'vatAmount' => (float) $invoice->vat_amount,
                'totalAmount' => (float) $invoice->total_amount,
                // العربون وما بقي على العميل — يُطبعان تحت الإجمالي متى قُبضت دفعة.
                'hasPayments' => $invoice->payments->isNotEmpty(),
                'paidAmount' => $invoice->paidAmount(),
                'paymentRemaining' => $invoice->remainingAmount(),
                'customerName' => $invoice->customer?->full_name,
                'customerPhone' => $invoice->customer?->phone,
                'customerTaxNumber' => $invoice->customer?->tax_number,
                'paymentMethod' => $invoice->paymentMethod?->name,
                'notes' => $invoice->notes,
                'deliveryAt' => $invoice->delivery_at?->toIso8601String(),
                'lines' => $invoice->lines->map(fn ($line) => [
                    'name' => $line->service_name,
                    'notes' => $line->notes,
                    'sku' => null,
                    'qty' => $line->qty,
                    'unitPrice' => (float) $line->unit_price,
                    'unitPriceBasis' => $line->isPricedPerSqm() ? 'sqm' : null,
                    'widthCm' => $line->width_cm !== null ? (float) $line->width_cm : null,
                    'heightCm' => $line->height_cm !== null ? (float) $line->height_cm : null,
                    'discountPct' => (float) $line->discount_pct,
                    'subtotal' => (float) $line->subtotal,
                ])->values(),
            ],
            'branch' => [
                'name' => $invoice->branch?->name,
                'phone' => $invoice->branch?->phone,
                'address' => $invoice->branch?->address,
                'taxNumber' => $isQuotation ? null : $invoice->branch?->tax_number,
                'logoUrl' => $invoice->branch?->getFirstMediaUrl('logo') ?: null,
            ],
        ]);
    }

    /**
     * The shared POS form payload (services, agents, payment methods, VAT and
     * loyalty config) — identical for create and edit.
     *
     * On the edit path the payload belongs to the **invoice**, not to whoever
     * opened it: its branch fixes the services, agents, payment methods, VAT and
     * loyalty, and its owning employee fixes the commission rates. A reviewer
     * from another role would otherwise seed the form from their own branch —
     * and a super admin, who has none, from an empty one.
     *
     * @return array<string, mixed>
     */
    private function posFormData(User $user, ListBranchAgentsAction $listBranchAgents, ?ServiceInvoice $invoice = null): array
    {
        // Shared with the Inertia layout, which already resolved this branch —
        // workBranch() hands back that same row instead of querying again.
        $branch = $invoice ? Branch::find($invoice->branch_id) : $user->workBranch();
        $branchId = $invoice ? (int) $invoice->branch_id : $user->branchId;
        $commissionUserId = $invoice ? (int) $invoice->user_id : $user->id;

        $loyalty = $branchId ? LoyaltyConfig::forBranch($branchId) : null;

        $paymentMethods = $branch
            ? $branch->enabledPaymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'requiresAttachment' => (bool) $method->requires_attachment,
            ])->values()
            : collect();

        return [
            // العمولات بموظف الفاتورة، والمفضّلة بمن يفتح الشاشة: تفضيلٌ شخصيّ
            // لمن يبيع الآن لا لصاحب الفاتورة (تاسك 76).
            'services' => $this->branchServiceOptions($branchId, $commissionUserId, $user->id),
            'agents' => $listBranchAgents->handle($branchId),
            'paymentMethods' => $paymentMethods,
            'vatPct' => (float) ($branch->vat_rate_override ?? 15),
            'loyalty' => [
                'active' => (bool) ($loyalty?->is_active),
                'redemptionRate' => (float) ($loyalty?->redemption_rate ?? 0),
                'minRedemptionPoints' => (int) ($loyalty?->min_redemption_points ?? 0),
            ],
        ];
    }

    /**
     * Active branch services shaped for the POS, each carrying the logged-in
     * employee's own commission rate (a service with no override earns 0%).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function branchServiceOptions(?int $branchId, int $userId, ?int $favoriteUserId = null): Collection
    {
        $commissionRates = UserService::query()
            ->where('user_id', $userId)
            ->pluck('commission_override_pct', 'branch_service_id');

        // تاسك 76: مفضّلات من يفتح الشاشة — استعلامٌ واحد لا واحدٌ لكل خدمة.
        $favorites = $favoriteUserId === null
            ? collect()
            : UserFavoriteService::query()
                ->where('user_id', $favoriteUserId)
                ->pluck('branch_service_id')
                ->flip();

        return BranchService::query()
            ->where('branch_services.branch_id', $branchId)
            ->where('branch_services.is_active', true)
            ->with([
                'serviceTemplate:id,name,sort_order',
                // خامات المخزون ومتاحُها — استعلامان ثابتان لا واحدٌ لكل خدمة.
                'materials.product:id,name,unit_id,is_sqm,current_stock',
                'materials.product.unit:id,name',
            ])
            // تاسك 82: ترتيب البائع هو ترتيب القالب — ضمٌّ صريح لأن الترتيب
            // على جدول القوالب لا على خدمات الفرع. ترتيبٌ لا يراه البائع لا قيمة له.
            ->join('service_templates', 'service_templates.id', '=', 'branch_services.service_template_id')
            ->orderBy('service_templates.sort_order')
            ->orderBy('service_templates.name')
            ->select('branch_services.*')
            ->get()
            ->map(fn (BranchService $service) => [
                'id' => $service->id,
                'name' => $service->serviceTemplate?->name,
                'baseCommissionPct' => (float) ($commissionRates[$service->id] ?? 0),
                'maxDiscountPct' => (float) $service->max_discount_pct,
                // حدّا سعر البيع للموظف (null = مفتوح من تلك الجهة) — تُقيَّد بهما نقطة البيع.
                'maxSellingPrice' => $service->max_selling_price !== null ? (float) $service->max_selling_price : null,
                'minSellingPrice' => $service->min_selling_price !== null ? (float) $service->min_selling_price : null,
                'pricingType' => $service->pricing_type?->value ?? 'unit',
                'pricePerSqm' => (float) $service->price_per_sqm,
                'agentCommissionPerSqm' => (float) $service->agent_commission_per_sqm,
                // Ready-made detail phrases; the POS joins them into the
                // placeholder of the line's free-text detail box.
                'noteExamples' => array_values($service->note_examples ?? []),
                'isTahazir' => $service->is_tahazir,
                // خدمة رفعها هذا الموظف أعلى قائمته (تاسك 76).
                'isFavorite' => $favorites->has($service->id),
                // تكلفة الخامات الافتراضية — تُعبّئ خانة السطر وتبقى قابلة للتعديل.
                'hasMaterials' => $service->has_materials,
                'materialsCost' => (float) $service->materials_cost,
                // تاسك 77: صفرٌ في التعريف = «تُحدَّد وقت البيع»، فتُفتح الخانة
                // للموظف على هذا السطر وحده.
                'materialsCostIsOpen' => CalculateServiceInvoiceAction::materialsCostIsOpen($service),
                // خامات المخزون التي ستُخصم عند اعتماد الفاتورة، ومتاحُ كلٍّ منها
                // لحظةَ فتح الشاشة. إرشاديّ لا مانع: الموظف يُنشئ فاتورة آجلة،
                // والخصم والفحص الحقيقي يقعان عند الاعتماد على الخادم.
                'materials' => $service->materials
                    ->filter(fn (BranchServiceMaterial $m) => $m->product !== null)
                    ->map(fn (BranchServiceMaterial $m) => [
                        'productId' => $m->product_id,
                        'name' => $m->product->name,
                        'unitName' => $m->product->is_sqm ? 'متر مربع' : $m->product->unit?->name,
                        'qtyPerUnit' => (float) $m->qty_per_unit,
                        'wastePct' => (float) $m->waste_pct,
                        'availableStock' => (float) $m->product->current_stock,
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn ($service) => $service['name'] !== null)
            ->values();
    }
}
