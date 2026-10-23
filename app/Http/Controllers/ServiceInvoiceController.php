<?php

namespace App\Http\Controllers;

use App\Actions\Agent\ListBranchAgentsAction;
use App\Actions\Customer\UpdateCustomerAction;
use App\Actions\InvoiceReturnRequest\CreateInvoiceReturnRequestAction;
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
use App\Enums\ServicePricingTypeEnum;
use App\Http\Requests\ServiceInvoice\CancelServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\ReturnServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\StoreServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\UpdateInvoiceCustomerRequest;
use App\Http\Requests\ServiceInvoice\UpdateServiceInvoiceRequest;
use App\Models\AgentService;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\BranchServiceMaterial;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\LoyaltyConfig;
use App\Models\Quotation;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\ServiceInvoiceShipment;
use App\Models\User;
use App\Models\UserFavoriteService;
use App\Models\UserService;
use App\Notifications\DueInvoiceNotification;
use App\Notifications\ReturnRequestNotification;
use App\Notifications\ServiceInvoiceReviewedNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
     * تاسك 118: شاشة «إنشاء فاتورة سريعة» — مبلغٌ وخدمةٌ وEnter. للموظف وحده:
     * فاتورته معلّقة بلا طريقة دفع، ومن فوقه يلزمه اختيار طريقة لا مكان لها هنا.
     *
     * الافتراضية أولاً، ثم مفضّلات الموظف وحدها (حتى 12). وبلا مفضّلات: ثماني
     * خدمات من الأكثر مبيعاً في فرعه آخر 30 يوماً ثم ترتيب القوالب — فلا تظهر
     * الشاشة فارغةً لموظفٍ جديد. والخدمة
     * المسعّرة بالمتر أو مفتوحة تكلفة الخامات خارجها: تحتاج ما لا تسأل عنه الشاشة.
     */
    public function quick(): Response
    {
        Gate::authorize('create', ServiceInvoice::class);

        $user = Auth::user();
        abort_unless($user->roleName->isEmployee(), 403);

        // سبب استبعاد الخدمة من الشاشة السريعة — null = تصلح لها.
        $all = $this->branchServiceOptions($user->branchId, $user->id, $user->id)
            ->map(fn (array $s) => [...$s, 'disabledReason' => match (true) {
                $s['pricingType'] !== ServicePricingTypeEnum::Unit->value => 'مسعّرة بالمتر — من شاشة فاتورة الخدمة',
                $s['materialsCostIsOpen'] => 'تكلفة الخامات تُحدَّد وقت البيع — من شاشة فاتورة الخدمة',
                default => null,
            }]);
        $services = $all->whereNull('disabledReason')->keyBy('id');

        $bestSellers = ServiceInvoiceLine::query()
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_lines.invoice_id')
            ->where('service_invoices.branch_id', $user->branchId)
            ->where('service_invoices.created_at', '>=', now()->subDays(30))
            ->where('service_invoices.status', '!=', InvoiceStatusEnum::CANCELLED->value)
            ->groupBy('service_invoice_lines.branch_service_id')
            ->orderByRaw('COUNT(*) DESC')
            ->pluck('service_invoice_lines.branch_service_id');

        $defaultId = $user->quick_service_id && $services->has($user->quick_service_id) ? (int) $user->quick_service_id : null;

        // مَن اختار مفضّلاته تظهر له وحدها حتى 12 (F1–F12)؛ وإلا فالاختيار التلقائي.
        $favorites = $services->where('isFavorite', true)->keys();
        $ids = collect([$defaultId])
            ->merge($favorites->isNotEmpty() ? $favorites : $bestSellers->merge($services->keys()))
            ->filter(fn ($id) => $services->has($id))
            ->unique()
            ->take($favorites->isNotEmpty() ? 12 : 8);

        return Inertia::render('pos/quick', [
            'services' => $ids->map(fn ($id) => [
                'id' => $id,
                'name' => $services[$id]['name'],
            ])->values(),
            // قائمة «اختيار الخدمات»: كل خدمات الفرع — الصالحة أولاً، والباقية
            // معطّلة بسببها كي لا يظنّها الموظف مفقودة.
            'eligibleServices' => $all->sortBy(fn (array $s) => $s['disabledReason'] !== null)->map(fn (array $s) => [
                'id' => $s['id'],
                'name' => $s['name'],
                'isFavorite' => $s['isFavorite'],
                'disabledReason' => $s['disabledReason'],
            ])->values(),
            'defaultServiceId' => $defaultId,
            'printInvoiceId' => session('quickPrintInvoiceId'),
        ]);
    }

    /**
     * Re-open an invoice in the POS form: a DUE one for its owning employee or
     * a reviewer in its branch correcting it — the materials cost above all,
     * which the employee may not touch (تاسك 70) — and an approved one for the
     * branch admin or super admin (ServiceInvoicePolicy::update). The form is
     * seeded from the invoice.
     */
    public function edit(ServiceInvoice $invoice, ListBranchAgentsAction $listBranchAgents, ResolveAvailablePointsAction $availablePoints): Response
    {
        Gate::authorize('update', $invoice);

        $invoice->load(['lines', 'helpers', 'shipments', 'user:id,name', 'customer:id,full_name,phone,tax_number,agent_id,customer_type,points_balance,tier', 'customer.addresses', 'invoiceAgents:id,service_invoice_id,agent_id,discount_amount,rebate_amount,line_commission_amount']);

        return Inertia::render('pos/service/index', [
            ...$this->posFormData(Auth::user(), $listBranchAgents, $invoice),
            'invoice' => $this->formInvoice($invoice, $availablePoints),
        ]);
    }

    /**
     * عروض الأسعار على شاشة نقطة البيع نفسها: إنشاء عرضٍ جديد، أو تعديله، أو
     * تحويله إلى فاتورة (`?convert=1`) — والأخير يفتح فاتورةً جديدة معبّأة
     * بالعرض تُحفظ من مسار الفاتورة العادي.
     */
    public function quotation(Request $request, ListBranchAgentsAction $listBranchAgents, ResolveAvailablePointsAction $availablePoints, ?Quotation $quotation = null): Response
    {
        $user = Auth::user();

        if (! $quotation) {
            Gate::authorize('create', [Quotation::class, InvoiceTypeEnum::SERVICE]);

            return Inertia::render('pos/service/index', [
                ...$this->posFormData($user, $listBranchAgents),
                'quotation' => ['id' => null, 'number' => null, 'mode' => 'edit'],
            ]);
        }

        abort_unless($quotation->type === InvoiceTypeEnum::SERVICE, 404);
        $convert = $request->boolean('convert');
        Gate::authorize($convert ? 'convert' : 'update', $quotation);

        $invoice = $quotation->load('customer.addresses', 'user:id,name', 'branch')->toInvoice();

        return Inertia::render('pos/service/index', [
            // التحويل فاتورةٌ جديدة باسم من يحوّلها؛ التعديل يبقى على صاحب العرض.
            ...$this->posFormData($user, $listBranchAgents, $convert ? null : $invoice),
            'invoice' => [...$this->formInvoice($invoice, $availablePoints), 'id' => null],
            'quotation' => ['id' => $quotation->id, 'number' => $quotation->quotation_number, 'mode' => $convert ? 'convert' : 'edit'],
        ]);
    }

    /**
     * الفاتورة كما تبذرها شاشة نقطة البيع — من فاتورةٍ محفوظة أو من عرض سعر
     * (`Quotation::toInvoice()`).
     *
     * @return array<string, mixed>
     */
    private function formInvoice(ServiceInvoice $invoice, ResolveAvailablePointsAction $availablePoints): array
    {
        $user = Auth::user();
        $branchId = (int) $invoice->branch_id;

        $loyalty = LoyaltyConfig::forBranch($branchId);
        $loyaltyActive = (bool) $loyalty->is_active;

        // العمولات تُقرأ بـ**موظف الفاتورة** لا بمن يعدّلها: المراجع لا يملك
        // صفوف user_services أصلاً، فلو قُرئت به لظهرت كل النسب صفراً على الشاشة
        // بينما يحسب الخادم عمولة الموظف الحقيقية عند الحفظ.
        $servicesById = $this->branchServiceOptions($branchId, (int) $invoice->user_id, $user->id)->keyBy('id');

        $coupon = $invoice->coupon_id ? Coupon::find($invoice->coupon_id) : null;

        return [
            'id' => $invoice->id,
            'invoiceNumber' => $invoice->invoice_number,
            // صاحب الفاتورة — تعرضه اللافتة حين يفتحها غيرُه (تاسك 70).
            'employeeName' => $invoice->user?->name,
            'isOwn' => $user->id === (int) $invoice->user_id,
            'statusLabel' => $invoice->status->label(),
            // حجز هذه الفاتورة نفسها لا يُطرح من رصيد عميلها هنا: نقاطها لها،
            // فإعادة إرسال العدد نفسه لا تُرفض ولا يظهر الرصيد منقوصاً مرتين.
            'customer' => $invoice->customer?->toPosArray(
                $loyalty,
                $loyaltyActive,
                $invoice->customer_id !== null ? $availablePoints->reserved((int) $invoice->customer_id, $invoice) : 0,
            ),
            // صفّ أنشأته عمولة بندٍ وحدها ليس مندوبَ فاتورة: لو عاد هنا لطُبّق
            // وضعه (خصم) على الفاتورة كلها عند التعديل. مندوب البند محفوظ على بنده.
            'agentIds' => $invoice->invoiceAgents
                ->reject(fn ($a) => (float) $a->discount_amount == 0 && (float) $a->rebate_amount == 0 && (float) $a->line_commission_amount > 0)
                ->pluck('agent_id')->values(),
            'coupon' => $coupon ? [
                'code' => $coupon->code,
                'type' => $coupon->discount_type->value,
                'value' => (float) $coupon->discount_value,
            ] : null,
            'pointsRedeemed' => (int) $invoice->points_redeemed,
            'manualDiscount' => (float) $invoice->manual_discount,
            'paymentMethodId' => $invoice->payment_method_id,
            'hasReceipt' => $invoice->hasReceipt(),
            'notes' => $invoice->notes,
            // تاسك 100: لا ملاحظة داخلية تُعاد — خانة التعديل تبدأ فارغة، وما
            // يُكتب فيها رسالةٌ جديدة في المحادثة لا استبدالٌ لما سبق.
            // «YYYY-MM-DD HH:MM» — الصيغة التي يقرأها منتقي الموعد في الواجهة.
            'deliveryAt' => $invoice->delivery_at?->format('Y-m-d H:i'),
            // تاسك 93 — التوصيل يعود إلى الشاشة كما حُفظ، فإعادة الحفظ لا
            // تُسقط سائقاً ولا عنواناً. تاسك 170: بمعرّف كل طلب، فتبقى تسويته.
            'shipments' => $invoice->shipments->map(fn ($shipment) => [
                'id' => $shipment->id,
                'providerId' => $shipment->provider_id,
                'zoneId' => $shipment->zone_id,
                'fee' => (float) $shipment->fee,
                'distanceKm' => $shipment->distance_km !== null ? (float) $shipment->distance_km : null,
                'customerAddressId' => $shipment->customer_address_id,
                'address' => $shipment->address,
            ])->values(),
            'helpers' => $invoice->helpers->map(fn ($helper) => [
                'userId' => $helper->user_id,
                'branchServiceId' => $helper->branch_service_id,
                'shareType' => $helper->share_type,
                'shareValue' => (float) $helper->share_value,
            ])->values(),
            'lines' => $invoice->lines->map(function ($line) use ($servicesById) {
                $service = $servicesById->get($line->branch_service_id);

                return [
                    'branchServiceId' => $line->branch_service_id,
                    'name' => $line->service_name,
                    'notes' => $line->notes,
                    'qty' => $line->qty,
                    // السطر المسعّر بمقاس — مربعاً كان أم طولياً (تاسك 80) —
                    // يعود إلى نقطة البيع بسعر وحدة قياسه، والسطر القديم الذي
                    // حُفظ بسعر القطعة يُقسم على قياسه أولاً فلا يتغيّر إجماليه
                    // لمجرد إعادة حفظه.
                    'unitPrice' => ServicePricingTypeEnum::tryFrom($service['pricingType'] ?? 'unit')?->isMeasured()
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
        ];
    }

    public function store(StoreServiceInvoiceRequest $request, CreateServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('create', ServiceInvoice::class);

        if ($quotationId = $request->validated('quotation_id')) {
            Gate::authorize('convert', Quotation::findOrFail($quotationId));
        }

        $invoice = $action->handle($request->validated(), $request->file('receipt'));

        if ($invoice->status === InvoiceStatusEnum::DUE) {
            Notification::send(
                BranchNotifiables::forBranch($invoice->branch_id, [Roles::BRANCH_ADMIN->value, Roles::ACCOUNTANT->value]),
                new DueInvoiceNotification($invoice->invoice_number, $invoice->id, InvoiceTypeEnum::SERVICE, (float) $invoice->total_amount),
            );
        }

        // تاسك 118: الشاشة السريعة لا تُغادَر — تعود إليها، ورقمُ ما يُطبع يصلها
        // لتطبعه في إطارٍ خفيّ.
        if ($request->boolean('quick')) {
            return to_route('pos.service.quick')
                ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح")
                ->with('quickPrintInvoiceId', $request->boolean('print') ? $invoice->id : null);
        }

        if ($request->boolean('print')) {
            return to_route('pos.service.print', $invoice)
                ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
        }

        if ($quotationId) {
            return to_route('invoices.show', ['type' => InvoiceTypeEnum::SERVICE->value, 'id' => $invoice->id])
                ->with('success', "تم تحويل عرض السعر إلى الفاتورة {$invoice->invoice_number}");
        }

        return to_route('pos.service.create')
            ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
    }

    /**
     * Persist an in-place edit — see edit() for who — then return to the
     * invoice viewer. Editing never moves the status (UpdateServiceInvoiceAction).
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
    public function returnInvoice(
        ReturnServiceInvoiceRequest $request,
        ServiceInvoice $invoice,
        ReturnServiceInvoiceAction $action,
        CreateInvoiceReturnRequestAction $createRequest,
    ): RedirectResponse {
        Gate::authorize('returnInvoice', $invoice);

        // تاسك 135: ما حُصِّل منه مالٌ لا يُردّ إلا بطلبٍ يعتمده المحاسب أو الإدارة؛
        // الآجلة بلا تحصيل تُسترجع فوراً كما كانت.
        if ($action->refundableCollected($invoice) > 0) {
            $returnRequest = $createRequest->handle($invoice, Auth::user(), $request->validated('reason'));

            Notification::send(
                BranchNotifiables::forBranch($invoice->branch_id, [Roles::BRANCH_ADMIN->value, Roles::ACCOUNTANT->value]),
                new ReturnRequestNotification($returnRequest, $invoice->invoice_number),
            );

            return back(fallback: route('invoices.index'))
                ->with('success', "تم رفع طلب استرجاع الفاتورة {$invoice->invoice_number} — تحت المراجعة");
        }

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

    /** Where to land after a customer edit when the request carries no referer. */
    private function customerEditFallback(ServiceInvoice $invoice): string
    {
        return route('invoices.show', ['type' => 'service', 'id' => $invoice->id]);
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

        return back(fallback: route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
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

    /**
     * تاسك 93 — «بيان توصيل»: الورقة التي تُسلَّم للسائق.
     *
     * تحمل ما يحتاجه ليصل ويسلّم — العميل وجوّاله وعنوانه، ورقم الطلب وبنوده،
     * واسمه هو، وقيمة التوصيل — و**لا تحمل أسعار البنود ولا أي عمولة**: السائق
     * طرفٌ خارجيّ لا يرى ما باع به المركز.
     *
     * ورقةٌ مستقلّة بزرٍّ مستقلّ لا تُطبع تلقائياً مع الفاتورة، بنصّ قرار العميل.
     */
    public function deliveryNote(ServiceInvoice $invoice, ServiceInvoiceShipment $shipment): Response
    {
        Gate::authorize('view', $invoice);

        // بيانٌ بلا سائق لا معنى له — الورقة كلّها موجّهةٌ إليه.
        abort_if($shipment->provider_id === null, 404, 'لا يوجد سائق على هذا الطلب.');

        $invoice->load([
            'lines',
            'customer:id,full_name,phone',
            'branch:id,name,phone,address',
        ]);
        $shipment->load(['provider:id,name,phone,type', 'zone:id,name']);

        return Inertia::render('invoices/delivery-note', [
            'note' => [
                'invoiceNumber' => $invoice->invoice_number,
                'createdAt' => $invoice->created_at?->toIso8601String(),
                // موعد تسليم العمل — يفيد السائق متى كان الطلب مرتبطاً بموعد.
                'deliveryAt' => $invoice->delivery_at?->toIso8601String(),
                'customerName' => $invoice->customer?->full_name,
                'customerPhone' => $invoice->customer?->phone,
                // اللقطة النصّية لا الدفتر: ما كُتب وقت الفوترة هو ما يُطبع.
                'address' => $shipment->address,
                'zoneName' => $shipment->zone?->name,
                'distanceKm' => $shipment->distance_km !== null ? (float) $shipment->distance_km : null,
                'providerName' => $shipment->provider?->name,
                'providerPhone' => $shipment->provider?->phone,
                // قيمة هذا الطلب وحده — لا رسوم طلبات الفاتورة الأخرى.
                'shippingFee' => (float) $shipment->fee,
                // ملاحظة العميل تفيد السائق (طابق، بوابة…)؛ والداخلية لا تخرج.
                'notes' => $invoice->notes,
                // البنود بأسمائها وكمّياتها وحدها — بلا سعرٍ ولا إجمالي.
                'lines' => $invoice->lines->map(fn ($line) => [
                    'name' => $line->service_name,
                    'notes' => $line->notes,
                    'qty' => $line->qty,
                ])->values(),
            ],
            'branch' => [
                'name' => $invoice->branch?->name,
                'phone' => $invoice->branch?->phone,
                'address' => $invoice->branch?->address,
                // بيانٌ داخليّ لا فاتورة ضريبية: لا رقم ضريبي ولا رمز ZATCA.
                'taxNumber' => null,
                'logoUrl' => $invoice->branch?->logoUrl(),
            ],
        ]);
    }

    public function print(ServiceInvoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'lines',
            'user:id,name',
            'customer:id,full_name,phone,tax_number',
            'paymentMethod:id,name',
            'branch:id,name,phone,address,tax_number',
            'payments',
            'shipments',
        ]);

        return Inertia::render('pos/service/print', $this->printProps($invoice));
    }

    /** ورقة عرض السعر — الورقة نفسها، بعنوان «عرض سعر» وبلا رقمٍ ضريبي. */
    public function quotationPrint(Quotation $quotation): Response
    {
        abort_unless($quotation->type === InvoiceTypeEnum::SERVICE, 404);
        Gate::authorize('print', $quotation);

        $props = $this->printProps($quotation->load('customer', 'user:id,name', 'branch')->toInvoice());

        return Inertia::render('pos/service/print', [
            ...$props,
            'invoice' => [...$props['invoice'], 'status' => 'quotation', 'statusLabel' => $quotation->status->label()],
        ]);
    }

    /**
     * @return array{invoice: array<string, mixed>, branch: array<string, mixed>}
     */
    private function printProps(ServiceInvoice $invoice): array
    {
        // ما لم يُقبض منه شيء ورقتُه ليست فاتورة ضريبية — فلا تحمل الرقم
        // الضريبي للفرع. والعربون سداد، فالمدفوعة جزئياً فاتورة ضريبية.
        $isQuotation = ! $invoice->status->isTaxDocument();

        return [
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
                'manualDiscount' => (float) $invoice->manual_discount,
                'vatPct' => (float) $invoice->vat_pct,
                'vatAmount' => (float) $invoice->vat_amount,
                'totalAmount' => (float) $invoice->total_amount,
                // تاسك 93: سطر التوصيل يُطبع مستقلاً فوق الإجمالي — لطلبٍ له توصيل (تاسك 109).
                'shippingFee' => $invoice->hasShipping() ? (float) $invoice->shipping_fee : null,
                // العربون وما بقي على العميل — يُطبعان تحت الإجمالي متى قُبضت دفعة.
                'hasPayments' => $invoice->payments->isNotEmpty(),
                'paidAmount' => $invoice->paidAmount(),
                'paymentRemaining' => $invoice->remainingAmount(),
                // الموظف صاحب الفاتورة — مَن أنشأها لا مَن يطبعها.
                'userName' => $invoice->user?->name,
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
                    'unitPriceBasis' => $line->isPricedPerSqm() ? $line->unit_price_basis?->value : null,
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
                'logoUrl' => $invoice->branch?->logoUrl(),
            ],
        ];
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
            // تاسك 93 — التوصيل: استعلامان ثابتان بجوار طرق الدفع، لا واحدٌ لكل
            // خدمة، فلا يزيدان عدد استعلامات الشاشة بعدد الخدمات.
            'shippingProviders' => DeliveryProvider::posOptions($branchId),
            'shippingZones' => DeliveryZone::posOptions($branchId),
            // من يملك كتابة قيمة التوصيل يدوياً: الإدارة لا الموظف. الواجهة
            // تُقفل الحقل، والخادم يتجاهل ما يُرسله الموظف على أي حال.
            'canEditShippingFee' => ! $user->roleName->isEmployee(),
            // المساعدون: موظفو الفرع النشطون (دور «موظف» وحده) عدا صاحب الفاتورة.
            'helperOptions' => User::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->whereKeyNot($commissionUserId)
                ->whereHas('roles', fn ($q) => $q->where('name', Roles::EMPLOYEE->value))
                ->orderBy('name')
                ->get(['id', 'name']),
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
            // تاسك 116: صفُّ الفرع نشط وقالبه نشط — تعطيل مدير النظام يُخفيها من كل فرع.
            ->sellable()
            ->with([
                'serviceTemplate:id,name,sort_order',
                // خامات المخزون ومتاحُها — استعلامان ثابتان لا واحدٌ لكل خدمة.
                'materials.product:id,name,unit_id,is_sqm,current_stock',
                'materials.product.unit:id,name',
                'agentCommissions',
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
                // تاسك 153: عمولة كل مندوب المحدَّدة لهذه الخدمة — تُعبَّأ وتُقفل على الموظف.
                'agentTerms' => $service->agentCommissions->mapWithKeys(fn (AgentService $t) => [
                    $t->agent_id => ['type' => $t->commission_type->value, 'value' => (float) $t->commission_value],
                ])->all(),
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
