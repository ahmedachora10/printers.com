<?php

namespace App\Actions\Invoice;

use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;

/**
 * Builds the ZATCA (Saudi e-invoicing) Phase-1 QR payload as a Base64-encoded
 * TLV (Tag-Length-Value) string. Each field is encoded as:
 *   chr(tag) . chr(byteLength) . value
 * concatenated in tag order 1..5, then Base64-encoded.
 */
class GenerateZatcaQrAction
{
    public function handle(ProductInvoice|ServiceInvoice $invoice): string
    {
        $branch = $invoice->branch;

        $fields = [
            1 => (string) ($branch->name ?? config('app.name')),
            2 => (string) ($branch->tax_number ?? ''),
            3 => $invoice->created_at?->toIso8601String() ?? now()->toIso8601String(),
            4 => number_format((float) $invoice->total_amount, 2, '.', ''),
            5 => number_format((float) $invoice->vat_amount, 2, '.', ''),
        ];

        $tlv = '';
        foreach ($fields as $tag => $value) {
            $tlv .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }
}
