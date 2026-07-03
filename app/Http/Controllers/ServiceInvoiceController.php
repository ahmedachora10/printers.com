<?php

namespace App\Http\Controllers;

use App\Actions\Customer\UpdateCustomerAction;
use App\Actions\ServiceInvoice\AttachServiceInvoiceCustomerAction;
use App\Actions\ServiceInvoice\CancelServiceInvoiceAction;
use App\Actions\ServiceInvoice\CreateServiceInvoiceAction;
use App\Actions\ServiceInvoice\MarkServiceInvoicePaidAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Enums\Roles;
use App\Http\Requests\ServiceInvoice\CancelServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\StoreServiceInvoiceRequest;
use App\Http\Requests\ServiceInvoice\UpdateInvoiceCustomerRequest;
use App\Http\Requests\ServiceInvoice\UpdateInvoicePaymentMethodRequest;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
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

        $user = Auth::user();
        $branchId = $user->branchId;
        $branch = Branch::find($branchId);

        // The logged-in employee's own commission rate per service. A service with
        // no row earns 0% for them — the preview reflects what will be recorded.
        $commissionRates = UserService::query()
            ->where('user_id', $user->id)
            ->pluck('commission_override_pct', 'branch_service_id');

        $services = BranchService::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('serviceTemplate:id,name')
            ->get()
            ->map(fn (BranchService $service) => [
                'id' => $service->id,
                'name' => $service->serviceTemplate?->name,
                'baseCommissionPct' => (float) ($commissionRates[$service->id] ?? 0),
                'maxDiscountPct' => (float) $service->max_discount_pct,
                'isTahazir' => $service->is_tahazir,
            ])
            ->filter(fn ($service) => $service['name'] !== null)
            ->values();

        $loyalty = $branchId ? LoyaltyConfig::forBranch($branchId) : null;
        $loyaltyActive = (bool) ($loyalty?->is_active);

        $agents = $this->branchAgents($branchId);

        $paymentMethods = $branch
            ? $branch->enabledPaymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'requiresAttachment' => (bool) $method->requires_attachment,
            ])->values()
            : collect();

        return Inertia::render('pos/service/index', [
            'services' => $services,
            'agents' => $agents,
            'paymentMethods' => $paymentMethods,
            'vatPct' => (float) ($branch->vat_rate_override ?? 15),
            'loyalty' => [
                'active' => $loyaltyActive,
                'redemptionRate' => (float) ($loyalty?->redemption_rate ?? 0),
                'minRedemptionPoints' => (int) ($loyalty?->min_redemption_points ?? 0),
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
            ->with(['lines', 'customer:id,full_name,phone', 'user:id,name', 'branch:id,name', 'paymentMethod:id,name', 'media'])
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
                        'qty' => $line->qty,
                        'unitPrice' => (float) $line->unit_price,
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

            return to_route('invoices.service.review')
                ->with('success', "تم إضافة بيانات العميل للفاتورة {$invoice->invoice_number}");
        }

        Gate::authorize('update', $customer);

        $updateAction->handle($customer, $request->validated());

        return to_route('invoices.service.review')
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
                    'sku' => null,
                    'qty' => $line->qty,
                    'unitPrice' => (float) $line->unit_price,
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
                'rate' => (float) ($agent->agentProfile?->rate ?? 0),
            ])
            ->values();
    }
}
