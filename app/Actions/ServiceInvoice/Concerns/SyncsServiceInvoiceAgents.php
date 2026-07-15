<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Models\ServiceInvoice;

/**
 * Writes the agent rows computed by CalculateServiceInvoiceAction onto a service
 * invoice's pivot. Shared by create and edit so the two never drift. Call inside
 * the surrounding transaction.
 */
trait SyncsServiceInvoiceAgents
{
    /**
     * Replace the invoice's agents with the freshly computed set. On edit the
     * existing (unsettled) rows are cleared first; a settled row is never touched
     * here — the caller must block editing an invoice already rolled into a payment.
     *
     * @param  list<array<string, mixed>>  $agentRows
     */
    protected function syncInvoiceAgents(ServiceInvoice $invoice, array $agentRows): void
    {
        $invoice->invoiceAgents()->delete();

        foreach ($agentRows as $row) {
            $invoice->invoiceAgents()->create($row);
        }
    }
}
