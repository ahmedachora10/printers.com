<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settles a due service invoice: marks it paid, stamps paid_at, and credits
 * loyalty points (points are earned only once the invoice becomes paid).
 * Only a due invoice can be settled here — paid/cancelled invoices are rejected.
 */
class MarkServiceInvoicePaidAction
{
    public function __construct(
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
    ) {}

    public function handle(ServiceInvoice $invoice): ServiceInvoice
    {
        if ($invoice->status !== InvoiceStatusEnum::DUE) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن اعتماد الدفع إلا لفاتورة آجلة.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatusEnum::PAID,
                'paid_at' => now(),
            ]);

            // Credit points now that the invoice is paid; no-ops for ineligible
            // customers (corporate, agent-linked, or inactive loyalty config).
            $this->earnLoyaltyPoints->handle($invoice);

            return $invoice;
        });
    }
}
