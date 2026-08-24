<?php

namespace App\Http\Controllers;

use App\Actions\InvoicePayment\RecordInvoicePaymentAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Enums\Roles;
use App\Http\Requests\InvoicePayment\StoreInvoicePaymentRequest;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Notifications\ServiceInvoiceReviewedNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * تسجيل دفعات الفاتورة (عربون + دفعات لاحقة) لكلا نوعي الفواتير.
 */
class InvoicePaymentController extends Controller
{
    public function store(StoreInvoicePaymentRequest $request, string $type, int $id, RecordInvoicePaymentAction $action): RedirectResponse
    {
        $invoice = $this->resolveInvoice($type, $id);
        Gate::authorize('recordPayment', $invoice);

        $payment = $action->handle(
            $invoice,
            $request->validated(),
            Auth::user(),
            $request->file('receipt'),
            $request->boolean('confirm_materials_shortage'),
        );

        $invoice->refresh();

        // اكتمل السداد بهذه الدفعة: يُبلَّغ الموظف صاحب الفاتورة كما لو اعتمدها
        // المحاسب دفعةً واحدة. الـ Action يرفض أي دفعة على فاتورة مسدَّدة، فلا
        // يمرّ هذا الفرع إلا مرة واحدة لكل فاتورة.
        if ($invoice instanceof ServiceInvoice && $invoice->status === InvoiceStatusEnum::PAID) {
            Notification::send(
                $this->settlementNotifiables($invoice),
                new ServiceInvoiceReviewedNotification(
                    $invoice->invoice_number,
                    $invoice->id,
                    (float) $invoice->total_amount,
                    InvoiceStatusEnum::PAID,
                ),
            );
        }

        $amount = number_format((float) $payment->amount, 2);
        $message = $invoice->status === InvoiceStatusEnum::PAID
            ? "تم تسجيل دفعة {$amount} ر.س واكتمل سداد الفاتورة {$invoice->invoice_number}"
            : "تم تسجيل دفعة {$amount} ر.س — المتبقي ".number_format($invoice->remainingAmount(), 2).' ر.س';

        return back()->with('success', $message);
    }

    /**
     * Resolve {type}/{id} to the concrete invoice model, or 404 — mirrors
     * InvoiceController::resolveInvoice.
     */
    private function resolveInvoice(string $type, int $id): ProductInvoice|ServiceInvoice
    {
        $enum = InvoiceTypeEnum::tryFrom($type);
        abort_if($enum === null, 404);

        return $enum->modelClass()::findOrFail($id);
    }

    /**
     * Who hears that the invoice is now settled: the employee who raised it, the
     * branch admin and super admins — minus whoever recorded the payment.
     *
     * @return Collection<int, User>
     */
    private function settlementNotifiables(ServiceInvoice $invoice): Collection
    {
        $recipients = collect();

        if ($invoice->user) {
            $recipients->push($invoice->user);
        }

        return $recipients
            ->concat(BranchNotifiables::forBranch($invoice->branch_id, [Roles::BRANCH_ADMIN->value]))
            ->concat(User::query()->whereHas('roles', fn ($q) => $q->where('name', Roles::SUPER_ADMIN->value))->get())
            ->unique('id')
            ->reject(fn (User $u) => $u->id === Auth::id())
            ->values();
    }
}
