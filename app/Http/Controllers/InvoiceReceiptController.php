<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceTypeEnum;
use App\Http\Requests\Invoice\UploadReceiptRequest;
use App\Models\InvoicePayment;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

        $media = $invoice->receipt();
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

        $media = $payment->receipt();
        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }

    /**
     * Attach or replace the receipt during review (accountant / branch admin).
     * Only service invoices flow through the review queue.
     */
    public function store(UploadReceiptRequest $request, ServiceInvoice $invoice): RedirectResponse
    {
        Gate::authorize('updateStatus', $invoice);

        DB::transaction(function () use ($invoice, $request) {
            $invoice->addMedia($request->file('receipt'))
                ->toMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
        });

        return back()->with('success', "تم حفظ إيصال الفاتورة {$invoice->invoice_number}");
    }

    private function resolveInvoice(string $type, int $id): ProductInvoice|ServiceInvoice
    {
        $enum = InvoiceTypeEnum::tryFrom($type);
        abort_if($enum === null, 404);

        return $enum->modelClass()::findOrFail($id);
    }
}
