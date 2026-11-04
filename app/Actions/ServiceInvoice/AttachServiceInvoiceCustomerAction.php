<?php

namespace App\Actions\ServiceInvoice;

use App\Models\Customer;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;

class AttachServiceInvoiceCustomerAction
{
    /**
     * Register (or link) a customer for a due invoice that has none — used when the
     * accountant adds a name/phone from the review queue. Mirrors the POS
     * found-or-create-by-phone semantics so a matching phone links the existing
     * customer instead of creating a duplicate.
     *
     * @param  array{full_name: string, phone: string, tax_number?: string|null}  $data
     */
    public function handle(ServiceInvoice $invoice, array $data): ServiceInvoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $customer = Customer::resolveWalkIn($invoice->branch_id, $data['full_name'], $data['phone'], $data['tax_number'] ?? null);

            $invoice->update(['customer_id' => $customer->id]);

            return $invoice->refresh();
        });
    }
}
