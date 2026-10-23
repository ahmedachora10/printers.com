<?php

namespace App\Http\Controllers;

use App\Actions\Quotation\SaveQuotationAction;
use App\Enums\InvoiceTypeEnum;
use App\Enums\QuotationStatusEnum;
use App\Http\Controllers\Concerns\BuildsPagedProps;
use App\Http\Requests\Quotation\RejectQuotationRequest;
use App\Http\Requests\Quotation\StoreProductQuotationRequest;
use App\Http\Requests\Quotation\StoreServiceQuotationRequest;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * عروض الأسعار: القائمة والحفظ وتسجيل ردّ العميل. شاشة الإدخال والطباعة هما
 * شاشتا نقطة البيع نفسها (`quotation()` و`quotationPrint()` في متحكّمَي الفواتير).
 */
class QuotationController extends Controller
{
    use BuildsPagedProps;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Quotation::class);

        $user = Auth::user();
        $status = QuotationStatusEnum::tryFrom((string) $request->input('status'));
        $search = trim((string) $request->input('search'));

        $paginator = Quotation::query()
            ->with(['customer:id,full_name,phone', 'user:id,name', 'branch:id,name'])
            ->when(! $user->roleName->isSuperAdmin(), fn ($q) => $q->where('branch_id', $user->branchId))
            ->when($user->roleName->isEmployee(), fn ($q) => $q->where('user_id', $user->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('quotation_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('quotations/index', [
            'quotations' => $this->pagedProp($paginator, fn (Quotation $q) => [
                'id' => $q->id,
                'number' => $q->quotation_number,
                'type' => $q->type->value,
                'status' => $q->status->value,
                'statusLabel' => $q->status->label(),
                'createdAt' => $q->created_at?->toIso8601String(),
                'customerName' => $q->customer?->full_name,
                'customerPhone' => $q->customer?->phone,
                'employeeName' => $q->user?->name,
                // اسم الفرع لمدير النظام وحده — غيره يرى فرعه فقط.
                'branchName' => $user->roleName->isSuperAdmin() ? $q->branch?->name : null,
                'totalAmount' => (float) $q->total_amount,
                'rejectionReason' => $q->rejection_reason,
                'invoiceId' => $q->invoice_id,
                'invoiceNumber' => $q->invoice_number,
                'can' => [
                    'update' => $user->can('update', $q),
                    'changeStatus' => $user->can('changeStatus', $q),
                    'print' => $user->can('print', $q),
                    'convert' => $user->can('convert', $q),
                ],
            ]),
            'filters' => ['status' => $status?->value, 'search' => $search],
            'statusOptions' => array_map(fn (QuotationStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()], QuotationStatusEnum::cases()),
            'canCreate' => [
                'service' => $user->can('create', [Quotation::class, InvoiceTypeEnum::SERVICE]),
                'product' => $user->can('create', [Quotation::class, InvoiceTypeEnum::PRODUCT]),
            ],
        ]);
    }

    public function store(Request $request, string $type, SaveQuotationAction $action): RedirectResponse
    {
        $type = InvoiceTypeEnum::from($type);
        Gate::authorize('create', [Quotation::class, $type]);

        $quotation = $action->create($type, $this->validated($type), Auth::user());

        return $this->saved($request, $quotation, "تم حفظ عرض السعر {$quotation->quotation_number}");
    }

    public function update(Request $request, Quotation $quotation, SaveQuotationAction $action): RedirectResponse
    {
        Gate::authorize('update', $quotation);

        $action->update($quotation, $this->validated($quotation->type));

        return $this->saved($request, $quotation, "تم تحديث عرض السعر {$quotation->quotation_number}");
    }

    /** أُرسل للعميل — ينتظر ردّه. */
    public function send(Quotation $quotation): RedirectResponse
    {
        return $this->transition($quotation, QuotationStatusEnum::PENDING, [QuotationStatusEnum::NEW]);
    }

    /** وافق العميل — صار قابلاً للتحويل إلى فاتورة. */
    public function accept(Quotation $quotation): RedirectResponse
    {
        return $this->transition($quotation, QuotationStatusEnum::APPROVED, [QuotationStatusEnum::NEW, QuotationStatusEnum::PENDING]);
    }

    public function reject(RejectQuotationRequest $request, Quotation $quotation): RedirectResponse
    {
        return $this->transition(
            $quotation,
            QuotationStatusEnum::REJECTED,
            [QuotationStatusEnum::NEW, QuotationStatusEnum::PENDING, QuotationStatusEnum::APPROVED],
            ['rejection_reason' => $request->validated('reason')],
        );
    }

    /**
     * @param  list<QuotationStatusEnum>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(Quotation $quotation, QuotationStatusEnum $to, array $from, array $extra = []): RedirectResponse
    {
        Gate::authorize('changeStatus', $quotation);

        if (! in_array($quotation->status, $from, true)) {
            return back()->withErrors(['status' => "لا يمكن نقل عرض السعر من «{$quotation->status->label()}» إلى «{$to->label()}»."]);
        }

        $quotation->update([
            'status' => $to,
            'rejection_reason' => null,
            ...$extra,
        ]);

        return back()->with('success', "عرض السعر {$quotation->quotation_number}: {$to->label()}");
    }

    /** @return array<string, mixed> */
    private function validated(InvoiceTypeEnum $type): array
    {
        $class = $type === InvoiceTypeEnum::SERVICE ? StoreServiceQuotationRequest::class : StoreProductQuotationRequest::class;

        return app($class)->validated();
    }

    private function saved(Request $request, Quotation $quotation, string $message): RedirectResponse
    {
        $to = $request->boolean('print')
            ? route("pos.{$quotation->type->value}.quotation.print", $quotation)
            : route('quotations.index');

        return redirect($to)->with('success', $message);
    }
}
