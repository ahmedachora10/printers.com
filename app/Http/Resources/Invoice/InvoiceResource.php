<?php

namespace App\Http\Resources\Invoice;

use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Models\InvoicePayment;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full invoice detail for the unified viewer (M13). Works for both
 * ProductInvoice and ServiceInvoice.
 *
 * @mixin ProductInvoice|ServiceInvoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * تاسك 94: يُرفع لحمولة الطباعة، فتُحجب أرقام التكلفة الداخلية عن كل دور
     * بلا استثناء — الورقة تصل العميل.
     */
    private bool $hidesInternalCosts = false;

    public function withoutInternalCosts(): static
    {
        $this->hidesInternalCosts = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = $this->resource instanceof ServiceInvoice
            ? InvoiceTypeEnum::SERVICE
            : InvoiceTypeEnum::PRODUCT;

        $refundedTotal = $this->relationLoaded('refunds')
            ? round((float) $this->refunds->sum('amount'), 2)
            : 0.0;

        // الدفعات (عربون + دفعات لاحقة): المحصَّل والمتبقي مصدرهما صفوف الدفعات،
        // بينما total_amount يبقى السعر المتفق عليه.
        $paidAmount = $this->resource->paidAmount();
        $paymentRemaining = $this->resource->remainingAmount();

        // القابل للإرجاع يُقاس على ما حُصِّل لا على الإجمالي — سقفُ
        // CreateRefundAction نفسه، فلا يعرض الزرّ مبلغاً يرفضه الخادم.
        $refundableRemaining = round(max($paidAmount - $refundedTotal, 0), 2);

        $user = $request->user();
        // الفاتورة تُمرَّر إلى الصلاحية: المحاسب يُمنع من مرتجع فاتورة معتمدة
        // (تاسك 42)، فيختفي الزر تلقائياً بلا شرط مكرَّر في الواجهة.
        $canRefund = $user !== null
            && $user->can('create', [Refund::class, $this->resource])
            && ($user->roleName->isSuperAdmin() || $user->branchId === $this->branch_id)
            && $this->status !== InvoiceStatusEnum::CANCELLED
            && $this->status !== InvoiceStatusEnum::RETURNED
            && $refundableRemaining > 0;

        // Settling a due service invoice straight from the viewer, mirroring the
        // review queue. Product invoices have no equivalent payment approval.
        $canApprovePayment = $user !== null
            && $this->resource instanceof ServiceInvoice
            && $this->status === InvoiceStatusEnum::DUE
            && $user->can('updateStatus', $this->resource);

        // The owning employee may re-edit their invoice while it is still DUE, and
        // return it (before or after approval) — but a return is blocked once the
        // invoice has been rolled into an agent payment.
        $isServiceInvoice = $this->resource instanceof ServiceInvoice;

        // The agents on the invoice: several via the pivot for a service invoice,
        // or the single row-level agent for a product invoice.
        $agents = $isServiceInvoice
            ? $this->invoiceAgents->map(fn ($a) => [
                'name' => $a->agent?->name,
                'mode' => $a->discount_mode->value,
                'rebate' => (float) $a->rebate_amount,
                'lineCommission' => (float) $a->line_commission_amount,
                'discount' => (float) $a->discount_amount,
                'isRebatePaid' => $a->agent_payment_id !== null,
            ])->values()->all()
            : ($this->agent ? [[
                'name' => $this->agent->name,
                'mode' => (float) $this->agent_rebate > 0 ? 'rebate' : 'discount',
                'rebate' => (float) $this->agent_rebate,
                'lineCommission' => 0.0,
                'discount' => (float) $this->agent_discount,
                'isRebatePaid' => $this->agent_payment_id !== null,
            ]] : []);

        $hasSettledAgent = $isServiceInvoice
            ? $this->invoiceAgents->whereNotNull('agent_payment_id')->isNotEmpty()
            : $this->agent_payment_id !== null;

        $canRecordPayment = $user !== null && $user->can('recordPayment', $this->resource);

        // «تم تسليم العمل» — الصلاحية وحدها تقرّر، وهي تُسقط المُسلَّمة والملغاة
        // والمرتجعة، فلا شرط مكرَّر في الواجهة (تاسك 31).
        $canDeliver = $user !== null && $isServiceInvoice && $user->can('deliver', $this->resource);

        $canEdit = $user !== null && $isServiceInvoice && $user->can('update', $this->resource);

        // ما بقي للمحاسب على فاتورة الموظف بعد إغلاق شاشة التعديل في وجهه:
        // بيانات العميل وطريقة الدفع. الزرّان يظهران هنا لمن تسمح له الصلاحية —
        // فالموظف صاحبُها يراهما كذلك، وهما ما يحتاجه المحاسب قبل الاعتماد.
        $canEditCustomer = $user !== null && $isServiceInvoice && $user->can('updateCustomer', $this->resource);
        // تاسك 99: قبل الاعتماد وبعده. وفاتورةٌ سُدّدت بدفعات طريقتُها على صفوف
        // دفعاتها، فالتعديل يُعرض على كل صفّ لا على الفاتورة.
        $canChangePaymentMethod = $user !== null && $user->can('changePaymentMethod', $this->resource);
        $hasPaymentRows = $this->relationLoaded('payments') && $this->payments->isNotEmpty();
        $canEditPaymentMethod = $canChangePaymentMethod && ! $hasPaymentRows;
        $canReturn = $user !== null
            && $isServiceInvoice
            && $user->can('returnInvoice', $this->resource)
            && ! $hasSettledAgent;

        return [
            'id' => $this->id,
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'invoiceNumber' => $this->invoice_number,
            'createdAt' => $this->created_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            // Why the invoice was rejected, for everyone who may view it — the
            // employee who raised it above all (تاسك 18). Product invoices have
            // no cancellation flow, hence no reason to carry.
            'cancellationReason' => $isServiceInvoice ? $this->resource->cancellation_reason : null,
            'cancelledByName' => $isServiceInvoice ? $this->resource->cancelledBy?->name : null,
            'cancelledAt' => $isServiceInvoice ? $this->resource->cancelled_at?->toIso8601String() : null,
            // موعد تسليم العمل للعميل — لفواتير الخدمات وحدها؛ تسليم المنتجات فوري.
            'deliveryAt' => $isServiceInvoice ? $this->resource->delivery_at?->toIso8601String() : null,
            'deliveryStatus' => $isServiceInvoice ? $this->resource->deliveryStatus()?->value : null,
            // ختم التسليم الفعلي ومَن سلّمه (تاسك 31).
            'deliveredAt' => $isServiceInvoice ? $this->resource->delivered_at?->toIso8601String() : null,
            'deliveredByName' => $isServiceInvoice ? $this->resource->deliveredBy?->name : null,
            'canDeliver' => $canDeliver,
            'subtotal' => (float) $this->subtotal,
            'tierDiscountPct' => (float) $this->tier_discount_pct,
            'tierDiscountAmount' => (float) $this->tier_discount_amount,
            'couponDiscount' => (float) $this->coupon_discount,
            'agentDiscount' => (float) $this->agent_discount,
            'agents' => $agents,
            'pointsRedeemed' => (int) $this->points_redeemed,
            'pointsDiscount' => (float) $this->points_discount,
            'vatPct' => (float) $this->vat_pct,
            'vatAmount' => (float) $this->vat_amount,
            'totalAmount' => (float) $this->total_amount,
            // تاسك 93 — التوصيل. المورد متعدّد الأشكال يخدم فاتورة المنتجات
            // أيضاً وهي بلا أعمدة شحن، فيُحرس كلُّ حقلٍ بنوع الفاتورة و`null`
            // فيها يعني «لا شحن على هذا النوع» فلا يُطبع له سطر.
            'shippingFee' => $isServiceInvoice ? (float) $this->resource->shipping_fee : null,
            'shippingProviderName' => $isServiceInvoice ? $this->resource->shippingProvider?->name : null,
            'employeeCommission' => $this->resource instanceof ServiceInvoice
                ? (float) $this->resource->employee_commission
                : null,
            // الموظف صاحب الفاتورة — مَن أنشأها لا مَن يطبعها، فيبقى الاسم واحداً
            // مهما تغيّر الطابع.
            'userName' => $this->user?->name,
            'customerName' => $this->customer?->full_name,
            'customerPhone' => $this->customer?->phone,
            'customerTaxNumber' => $this->customer?->tax_number ?? null,
            'paymentMethod' => $this->paymentMethodLabel(),
            // يسبق اختيارَ نافذة «طريقة الدفع» في شاشة الفاتورة، ويحرس زرّ
            // الاعتماد — طريقة الفاتورة نفسها لا طريقة دفعاتها.
            'paymentMethodId' => $this->payment_method_id,
            // Invoice-level remark for the customer — distinct from the
            // per-line detail carried on InvoiceLineResource::notes.
            'notes' => $this->notes,
            // تاسك 95: الملاحظة الداخلية — تعليمات تنفيذ أو تنبيه، لا يراها
            // العميل. تُحجب عمّن لا يملكها بنفس قاعدة أرقام التكلفة، وتُحذف
            // من حمولة الطباعة كاملةً عبر withoutInternalCosts().
            'internalNotes' => $this->showsInternalCostsTo($request) ? $this->internal_notes : null,
            'canEditInternalNotes' => $this->canEditInternalNotes($request),
            'receiptUrl' => $this->receiptUrl(),
            // تاسك 94: أرقام السطر الداخلية (تكلفة الخامات، عمولة الموظف،
            // الشريحة) تُحجب عمّن لا يملكها — قرارٌ واحد يُتخذ هنا ويُمرَّر
            // لكل سطر، فلا يقرأ كل سطر الفاتورة الأم من جديد.
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                fn ($line) => (new InvoiceLineResource($line))->showingInternalCosts($this->showsInternalCostsTo($request)),
            )->values()),
            'refundedTotal' => $refundedTotal,
            'refundableRemaining' => $refundableRemaining,
            'isFullyRefunded' => $refundedTotal > 0 && $refundableRemaining <= 0,
            'paidAmount' => $paidAmount,
            'paymentRemaining' => $paymentRemaining,
            'canRecordPayment' => $canRecordPayment,
            'payments' => $this->whenLoaded('payments', fn () => $this->payments
                ->sortBy('paid_at')
                ->map(fn (InvoicePayment $payment) => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'paidAt' => $payment->paid_at?->toIso8601String(),
                    'paymentMethod' => $payment->paymentMethod?->name,
                    'paymentMethodId' => $payment->payment_method_id,
                    'recordedByName' => $payment->recordedBy?->name,
                    'notes' => $payment->notes,
                    'receiptUrl' => $payment->receiptUrl(),
                ])->values()->all()),
            // تاسك 99: تعديل طريقة كل دفعة — المبلغ والتاريخ لا يُمسّان.
            'canEditPaymentRows' => $canChangePaymentMethod && $hasPaymentRows,
            'canRefund' => $canRefund,
            'canApprovePayment' => $canApprovePayment,
            'canEdit' => $canEdit,
            'canEditCustomer' => $canEditCustomer,
            'canEditPaymentMethod' => $canEditPaymentMethod,
            'canReturn' => $canReturn,
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds
                ->map(fn (Refund $refund) => [
                    'id' => $refund->id,
                    'amount' => (float) $refund->amount,
                    'reason' => $refund->reason,
                    'stockReversed' => (bool) $refund->stock_reversed,
                    'userName' => $refund->user?->name,
                    'createdAt' => $refund->created_at?->toIso8601String(),
                ])->values()->all()),
            'branch' => [
                'name' => $this->branch?->name,
                'phone' => $this->branch?->phone,
                'address' => $this->branch?->address,
                'taxNumber' => $this->branch?->tax_number,
                'logoUrl' => $this->branch?->getFirstMediaUrl('logo') ?: null,
            ],
        ];
    }

    /**
     * طريقة الدفع كما يقرؤها تقرير المبيعات: فاتورةٌ سُدّدت بدفعات طريقتُها
     * طرقُ دفعاتها (وطريقة الفاتورة لصفٍّ قديم بلا طريقة)، وإلا فطريقة الفاتورة.
     * بغير هذا عرضت فاتورة العربون «طريقة الدفع: —» وهي مسدَّدة بالشبكة.
     */
    private function paymentMethodLabel(): ?string
    {
        $own = $this->paymentMethod?->name;

        if (! $this->relationLoaded('payments') || $this->payments->isEmpty()) {
            return $own;
        }

        $names = $this->payments
            ->map(fn (InvoicePayment $payment) => $payment->paymentMethod?->name ?? $own)
            ->filter()
            ->unique()
            ->values();

        return $names->isEmpty() ? $own : $names->implode(' + ');
    }

    /**
     * تاسك 94 — من يرى أرقام التكلفة الداخلية على سطور الفاتورة؟
     *
     * المراجعون (سوبر أدمن، مدير فرع، محاسب) دائماً، وصاحبُ الفاتورة الموظف
     * على فاتورته وحدها: هو من قد يكتب تكلفة الخامة منذ تاسك 77، وهي تُخصم من
     * أساس عمولته منذ تاسك 7 — فمن حقّه أن يرى ما خُصم. ولا أحد غيرهما،
     * وبوابة المندوب لا تراها بحال.
     *
     * ولا تُطبع لأحد إطلاقاً: InvoiceController::print() يحذفها من الحمولة.
     */
    private function showsInternalCostsTo(Request $request): bool
    {
        $user = $request->user();

        if ($this->hidesInternalCosts || $user === null) {
            return false;
        }

        $role = $user->roleName;

        if ($role?->isSuperAdmin() || $role?->isBranchAdmin() || $role?->isAccountant()) {
            return true;
        }

        return $role?->isEmployee() && (int) $this->user_id === $user->id;
    }

    /**
     * تاسك 95 — من يكتب الملاحظة الداخلية أو يصحّحها؟ من يراها: المراجعون
     * وصاحبُ الفاتورة. وتبقى قابلة للتعديل **بعد الاعتماد** — فهي تعليمات
     * تنفيذٍ لا رقمٌ مالي، ولا تغيّر شيئاً في مبلغ الفاتورة ولا في حالتها.
     * والملغاة والمرتجعة أُغلقت قصّتها فلا تُعدَّل. الحكم النهائي في السياسة.
     */
    private function canEditInternalNotes(Request $request): bool
    {
        return $this->showsInternalCostsTo($request)
            && $this->status !== InvoiceStatusEnum::CANCELLED
            && $this->status !== InvoiceStatusEnum::RETURNED;
    }
}
