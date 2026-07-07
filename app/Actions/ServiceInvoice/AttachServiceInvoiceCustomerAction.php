<?php

namespace App\Actions\ServiceInvoice;

use App\Enums\CustomerTypeEnum;
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
            $existing = Customer::query()
                ->where('branch_id', $invoice->branch_id)
                ->where('phone', $data['phone'])
                ->first();

            $customerId = $existing?->id ?? Customer::create([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'tax_number' => $data['tax_number'] ?? null,
                'branch_id' => $invoice->branch_id,
                'customer_type' => CustomerTypeEnum::Individual,
                'is_active' => true,
            ])->id;

            $invoice->update(['customer_id' => $customerId]);

            return $invoice->refresh();
        });
    }
}
