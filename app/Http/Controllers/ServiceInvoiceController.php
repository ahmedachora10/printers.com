<?php

namespace App\Http\Controllers;

use App\Actions\Customer\UpdateCustomerAction;
use App\Actions\ServiceInvoice\AttachServiceInvoiceCustomerAction;
use App\Actions\ServiceInvoice\CancelServiceInvoiceAction;
use App\Actions\ServiceInvoice\CreateServiceInvoiceAction;
use App\Actions\ServiceInvoice\DeleteServiceInvoiceAction;
use App\Actions\ServiceInvoice\MarkServiceInvoicePaidAction;
use App\Actions\ServiceInvoice\UpdateServiceInvoiceAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Enums\Roles;
use App\Http\Requests\ServiceInvoice\CancelServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\StoreServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\UpdateInvoiceCustomerRequest;
use App\Http\Requests\ServiceInvoice\UpdateInvoicePaymentMethodRequest;
use App\Http\Requests\ServiceInvoice\UpdateServiceInvoiceRequest;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Models\UserService;
use App\Notifications\DueInvoiceNotification;
use App\Notifications\ServiceInvoiceReviewedNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class ServiceInvoiceController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', ServiceInvoice::class);

        return Inertia::render('pos/service/index', $this->posFormData(Auth::user()));
    }

    /**
     * Re-open a DUE invoice in the POS form for its owning employee to edit
     * (before an accountant approves it). The form is seeded from the invoice.
     */
    public function edit(ServiceInvoice $invoice): Response
    {
        Gate::authorize('update', $invoice);

        $user = Auth::user();
        $branchId = (int) $invoice->branch_id;

        $invoice->load(['lines', 'customer:id,full_name,phone,agent_id,customer_type,points_balance,tier', 'invoiceAgents:id,service_invoice_id,agent_id']);

        $loyalty = LoyaltyConfig::forBranch($branchId);
        $loyaltyActive = (bool) $loyalty->is_active;

        $servicesById = $this->branchServiceOptions($branchId, $user->id)->keyBy('id');

        $coupon = $invoice->coupon_id ? Coupon::find($invoice->coupon_id) : null;

        return Inertia::render('pos/service/index', [
            ...$this->posFormData($user),
            'invoice' => [
                'id' => $invoice->id,
                'invoiceNumber' => $invoice->invoice_number,
                'customer' => $invoice->customer?->toPosArray($loyalty, $loyaltyActive),
                'agentIds' => $invoice->invoiceAgents->pluck('agent_id')->values(),
                'coupon' => $coupon ? [
                    'code' => $coupon->code,
                    'type' => $coupon->discount_type->value,
                    'value' => (float) $coupon->discount_value,
                ] : null,
                'pointsRedeemed' => (int) $invoice->points_redeemed,
                'paymentMethodId' => $invoice->payment_method_id,
                'hasReceipt' => $invoice->hasReceipt(),
                'lines' => $invoice->lines->map(function ($line) use ($servicesById) {
                    $service = $servicesById->get($line->branch_service_id);

                    return [
                        'branchServiceId' => $line->branch_service_id,
                        'name' => $line->service_name,
                        'notes' => $line->notes,
                        'qty' => $line->qty,
                        'unitPrice' => (float) $line->unit_price,
                        'discountPct' => (float) $line->discount_pct,
                        'maxDiscountPct' => (float) ($service['maxDiscountPct'] ?? 0),
                        'baseCommissionPct' => (float) ($service['baseCommissionPct'] ?? $line->commission_pct),
                        'isTahazir' => (bool) ($service['isTahazir'] ?? false),
                        'pricingType' => $service['pricingType'] ?? 'unit',
                        'pricePerSqm' => (float) ($service['pricePerSqm'] ?? 0),
                        'agentCommissionPerSqm' => (float) ($service['agentCommissionPerSqm'] ?? 0),
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
     * Persist an employee's in-place edit of their own DUE invoice, then return
     * to the invoice viewer. The invoice stays DUE for the accountant to review.
     */
    public function update(UpdateServiceInvoiceRequest $request, ServiceInvoice $invoice, UpdateServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('update', $invoice);

        $action->handle($invoice, $request->validated(), $request->file('receipt'));

        return to_route('invoices.show', ['type' => InvoiceTypeEnum::SERVICE->value, 'id' => $invoice->id])
            ->with('success', "تم تحديث الفاتورة {$invoice->invoice_number} بنجاح");
    }

    /**
     * Soft-delete an employee's own invoice (before or after approval) and unwind
     * its accruals. Only the invoice's owner may do this — never an accountant.
     */
    public function destroy(ServiceInvoice $invoice, DeleteServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('delete', $invoice);

        $number = $invoice->invoice_number;
        $action->handle($invoice);

        return to_route('invoices.index')
            ->with('success', "تم حذف الفاتورة {$number} بنجاح");
    }

    /**
     * Review queue of due service invoices awaiting an accountant/branch-admin
     * decision (settle or cancel). Super admins see every branch.
     */
    public function review(): Response
    {
        Gate::authorize('review', ServiceInvoice::class);

        $user = Auth::user();
        $isSuperAdmin = $user->roleName->isSuperAdmin();

        $dueInvoices = ServiceInvoice::query()
            ->where('status', InvoiceStatusEnum::DUE)
            ->when(! $isSuperAdmin, fn ($q) => $q->where('branch_id', $user->branchId))
            ->with(['lines', 'customer:id,full_name,phone,tax_number', 'user:id,name', 'branch:id,name', 'paymentMethod:id,name', 'media'])
            ->latest()
            ->get();

        // The payment-method options a reviewer may switch to depend on the
        // invoice's branch (super admins see several). Resolve each branch's
        // enabled methods once, then reuse per invoice.
        $methodsByBranch = $dueInvoices->pluck('branch_id')->unique()->mapWithKeys(function ($branchId) {
            $branch = Branch::find($branchId);

            return [$branchId => $branch
                ? $branch->enabledPaymentMethods()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()
                : collect()];
        });

        $invoices = $dueInvoices
            ->map(function (ServiceInvoice $invoice) use ($methodsByBranch) {
                $options = collect($methodsByBranch[$invoice->branch_id] ?? []);

                // Keep the current method selectable even if it was later disabled.
                if ($invoice->paymentMethod && ! $options->contains('id', $invoice->payment_method_id)) {
                    $options = $options->prepend(['id' => $invoice->payment_method_id, 'name' => $invoice->paymentMethod->name]);
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
                    'lines' => $invoice->lines->map(fn ($line) => [
                        'name' => $line->service_name,
                        'notes' => $line->notes,
                        'qty' => $line->qty,
                        'unitPrice' => (float) $line->unit_price,
                        'widthCm' => $line->width_cm !== null ? (float) $line->width_cm : null,
                        'heightCm' => $line->height_cm !== null ? (float) $line->height_cm : null,
                        'discountPct' => (float) $line->discount_pct,
                        'subtotal' => (float) $line->subtotal,
                    ])->values(),
                ];
            });

        return Inertia::render('invoices/review', [
            'invoices' => $invoices,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function markPaid(ServiceInvoice $invoice, MarkServiceInvoicePaidAction $action): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        $action->handle($invoice);

        Notification::send(
            $this->reviewNotifiables($invoice),
            new ServiceInvoiceReviewedNotification($invoice->invoice_number, $invoice->id, (float) $invoice->total_amount, InvoiceStatusEnum::PAID),
        );

        // Redirect back so approving from the invoice viewer returns to the
        // (now paid) invoice; from the review queue it falls back there too.
        return back()->with('success', "تم اعتماد دفع الفاتورة {$invoice->invoice_number}");
    }

    /**
     * Set the invoice's customer from the review queue. For a linked customer this
     * corrects the shared record's name/phone (affects the customer everywhere);
     * for a due invoice with no customer (walk-in), it registers/links one by phone.
     */
    public function updateCustomer(
        UpdateInvoiceCustomerRequest $request,
        ServiceInvoice $invoice,
        UpdateCustomerAction $updateAction,
        AttachServiceInvoiceCustomerAction $attachAction,
    ): RedirectResponse {
        $customer = $invoice->customer;

        if ($customer === null) {
            Gate::authorize('create', Customer::class);

            $attachAction->handle($invoice, $request->validated());

            return redirect()->back(fallback: route('invoices.service.review'))
                ->with('success', "تم إضافة بيانات العميل للفاتورة {$invoice->invoice_number}");
        }

        Gate::authorize('update', $customer);

        $updateAction->handle($customer, $request->validated());

        return redirect()->back(fallback: route('invoices.service.review'))
            ->with('success', "تم تحديث بيانات العميل للفاتورة {$invoice->invoice_number}");
    }

    /**
     * Correct the payment method (transfer, card, mada, …) of a due invoice from
     * the review queue. Restricted to the branch's enabled methods by the request.
     */
    public function updatePaymentMethod(UpdateInvoicePaymentMethodRequest $request, ServiceInvoice $invoice): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        $invoice->update(['payment_method_id' => $request->validated('payment_method_id')]);

        return to_route('invoices.service.review')
            ->with('success', "تم تحديث طريقة الدفع للفاتورة {$invoice->invoice_number}");
    }

    public function cancel(CancelServiceInvoiceRequest $request, ServiceInvoice $invoice, CancelServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        $reason = $request->validated()['reason'];
        $action->handle($invoice, $reason);

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

        $invoice->load(['lines', 'customer:id,full_name,phone', 'paymentMethod:id,name', 'branch:id,name,phone,address,tax_number']);

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
                'customerName' => $invoice->customer?->full_name,
                'customerPhone' => $invoice->customer?->phone,
                'paymentMethod' => $invoice->paymentMethod?->name,
                'lines' => $invoice->lines->map(fn ($line) => [
                    'name' => $line->service_name,
                    'notes' => $line->notes,
                    'sku' => null,
                    'qty' => $line->qty,
                    'unitPrice' => (float) $line->unit_price,
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
                'taxNumber' => $invoice->branch?->tax_number,
            ],
        ]);
    }

    /**
     * The shared POS form payload (services, agents, payment methods, VAT and
     * loyalty config) for a user's branch — identical for create and edit.
     *
     * @return array<string, mixed>
     */
    private function posFormData(User $user): array
    {
        $branchId = $user->branchId;
        $branch = Branch::find($branchId);

        $loyalty = $branchId ? LoyaltyConfig::forBranch($branchId) : null;

        $paymentMethods = $branch
            ? $branch->enabledPaymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'requiresAttachment' => (bool) $method->requires_attachment,
            ])->values()
            : collect();

        return [
            'services' => $this->branchServiceOptions($branchId, $user->id),
            'agents' => $this->branchAgents($branchId),
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
    private function branchServiceOptions(?int $branchId, int $userId): Collection
    {
        $commissionRates = UserService::query()
            ->where('user_id', $userId)
            ->pluck('commission_override_pct', 'branch_service_id');

        return BranchService::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('serviceTemplate:id,name')
            ->get()
            ->map(fn (BranchService $service) => [
                'id' => $service->id,
                'name' => $service->serviceTemplate?->name,
                'baseCommissionPct' => (float) ($commissionRates[$service->id] ?? 0),
                'maxDiscountPct' => (float) $service->max_discount_pct,
                'pricingType' => $service->pricing_type?->value ?? 'unit',
                'pricePerSqm' => (float) $service->price_per_sqm,
                'agentCommissionPerSqm' => (float) $service->agent_commission_per_sqm,
                'isTahazir' => $service->is_tahazir,
            ])
            ->filter(fn ($service) => $service['name'] !== null)
            ->values();
    }

    /**
     * Active agents for the branch, with the terms the POS previews.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function branchAgents(?int $branchId): Collection
    {
        return Agent::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('agentProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'discountMode' => $agent->agentProfile?->discount_mode?->value,
                'discountType' => $agent->agentProfile?->discount_type?->value ?? 'percentage',
                'rate' => (float) ($agent->agentProfile?->rate ?? 0),
            ])
            ->values();
    }
}
