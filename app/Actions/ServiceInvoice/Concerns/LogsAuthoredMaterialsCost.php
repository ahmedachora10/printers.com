<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Models\ServiceInvoice;

/**
 * تاسك 77: يسجّل في سجلّ النشاط من كتب تكلفة خامات سطرٍ «مفتوح التكلفة».
 *
 * الرقم يقرّر أرضية سعر البيع (تاسك 65) ويُخصم من أساس عمولة كاتبه (تاسك 54)،
 * فكتابتُه بيد صاحب المصلحة تستوجب أثراً يُراجَع — والفاتورة نفسها لا تحمل
 * عموداً يميّز رقماً كتبه الموظف من رقمٍ جاء من تعريف الخدمة.
 */
trait LogsAuthoredMaterialsCost
{
    /** @param list<array<string, mixed>> $lines rows from CalculateServiceInvoiceAction */
    protected function logAuthoredMaterialsCost(ServiceInvoice $invoice, array $lines): void
    {
        $authored = array_values(array_filter($lines, fn (array $line) => ($line['materials_cost_authored'] ?? false) === true));

        if ($authored === []) {
            return;
        }

        activity('invoices')
            ->performedOn($invoice)
            ->causedBy(auth()->user())
            ->withProperties([
                'lines' => array_map(fn (array $line) => [
                    'service' => $line['service_name'],
                    'materialsCost' => $line['materials_cost'],
                    'materialsTotal' => $line['materials_total'],
                ], $authored),
            ])
            ->log("تكلفة خامات كتبها الموظف على الفاتورة {$invoice->invoice_number}");
    }
}
