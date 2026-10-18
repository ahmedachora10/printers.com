<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;

class UpdateExpenseAction
{
    /** @param array<string, mixed> $data */
    public function handle(Expense $expense, array $data): Expense
    {
        $qty = $data['qty'] ?? $expense->qty;
        $unitPrice = $data['unit_price'] ?? $expense->unit_price;
        $data['total'] = bcmul((string) $qty, (string) $unitPrice, 2);
        $attachments = $data['attachments'] ?? [];
        $removeIds = $data['remove_attachment_ids'] ?? [];
        // النافذة ترسل الروابط كاملةً دائماً، وقائمةٌ فارغة لا تُرسَل في multipart — فالغياب = بلا ربط.
        $invoices = $data['invoices'] ?? [];
        unset($data['attachments'], $data['remove_attachment_ids'], $data['invoices']);

        return DB::transaction(function () use ($expense, $data, $attachments, $removeIds, $invoices) {
            $before = $this->invoicesLabel($expense);

            $expense->update($data);
            $expense->syncInvoices($invoices);

            // الروابط في جدولٍ وسيط لا يراه logFillable — تُسجَّل هنا كي يبقى سجلّ
            // ما بعد الاعتماد (تاسك 113) كاملاً.
            $after = $this->invoicesLabel($expense);
            if ($before !== $after) {
                activity('expenses')->performedOn($expense)->causedBy(auth()->user())->event('updated')
                    ->withProperties(['old' => ['invoices' => $before ?: null], 'attributes' => ['invoices' => $after ?: null]])
                    ->log('updated');
            }

            $expense->attachments()->whereIn('id', $removeIds)->each->delete();

            foreach ($attachments as $attachment) {
                $expense->addMedia($attachment)->toMediaCollection(Expense::ATTACHMENT);
            }

            return $expense->fresh();
        });
    }

    /** «SINV-1 (60.00)، SINV-2 (40.00)» — للمقارنة وللسجلّ. */
    private function invoicesLabel(Expense $expense): string
    {
        return $expense->invoices()->orderBy('service_invoices.id')->get()
            ->map(fn (ServiceInvoice $invoice) => $invoice->invoice_number.' ('.number_format((float) $invoice->pivot->amount, 2, '.', '').')')
            ->implode('، ');
    }
}
