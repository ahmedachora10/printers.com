<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceTypeEnum;
use App\Http\Requests\Invoice\UploadReceiptRequest;
use App\Models\InvoicePayment;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

class InvoiceReceiptController extends Controller
{
    /**
     * Stream the stored payment receipt inline to authorized branch staff.
     * Receipts live on the private disk and are never exposed by public URL.
     */
    public function show(string $type, int $id, Request $request): Response
    {
        $invoice = $this->resolveInvoice($type, $id);
        Gate::authorize('view', $invoice);

        // تاسك 229: ?media= يختار إيصالاً بعينه من إيصالات هذه الفاتورة وحدها.
        $media = $invoice->receipt($request->integer('media') ?: null);
        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }

    /**
     * Stream the receipt attached to a single payment. Authorization rides on
     * the parent invoice — whoever may view the invoice may view the proof of
     * what was collected against it.
     */
    public function payment(InvoicePayment $payment, Request $request): Response
    {
        Gate::authorize('view', $payment->invoice);

        $media = $payment->receipt($request->integer('media') ?: null);
        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }

    /**
     * Attach more receipts during review (accountant / branch admin).
     * Only service invoices flow through the review queue.
     */
    public function store(UploadReceiptRequest $request, ServiceInvoice $invoice): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        DB::transaction(fn () => $invoice->addReceipts($request->file('receipts', [])));

        return back()->with('success', "تم حفظ إيصال الفاتورة {$invoice->invoice_number}");
    }

    /**
     * تاسك 229: حذف إيصالٍ بعينه — لمدير الفرع والسوبر أدمن وحدهما، على ما يرونه.
     * الحذف يُكتب في سجلّ نشاط الفاتورة: الملف نفسه لا يعود.
     */
    public function destroy(Media $media, Request $request): RedirectResponse
    {
        $owner = $media->model;

        abort_unless(
            $media->collection_name === ServiceInvoice::RECEIPT_COLLECTION
                && ($owner instanceof ServiceInvoice || $owner instanceof ProductInvoice || $owner instanceof InvoicePayment || $owner instanceof Refund),
            404,
        );

        $role = $request->user()->roleName;
        abort_unless($role?->isSuperAdmin() || $role?->isBranchAdmin(), 403);

        $invoice = $owner instanceof InvoicePayment || $owner instanceof Refund ? $owner->invoice : $owner;
        Gate::authorize('view', $owner instanceof Refund ? $owner : $invoice);

        DB::transaction(function () use ($media, $invoice, $owner, $request) {
            activity('invoices')
                ->causedBy($request->user())
                ->performedOn($invoice ?? $owner)
                ->withProperties(['file' => $media->file_name])
                ->log('receipt deleted');

            $media->delete();
        });

        return back()->with('success', 'تم حذف الإيصال');
    }

    private function resolveInvoice(string $type, int $id): ProductInvoice|ServiceInvoice
    {
        $enum = InvoiceTypeEnum::tryFrom($type);
        abort_if($enum === null, 404);

        return $enum->modelClass()::findOrFail($id);
    }
}
