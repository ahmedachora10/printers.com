<?php

namespace App\Actions\ServiceInvoice;

use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ختم «تم تسليم العمل» على فاتورة الخدمة (تاسك 31).
 *
 * لا يمسّ المال ولا الحالة المحاسبية للفاتورة إطلاقاً: الفاتورة الآجلة تبقى
 * آجلة بعد تسليم عملها، فالتحصيل شأن آخر. كل ما يتغيّر هو ختم التسليم ومَن
 * سلّمه، وهما ما يقلبان حالة عمود موعد التسليم إلى «تم تسليم العمل».
 */
class MarkServiceInvoiceDeliveredAction
{
    public function handle(ServiceInvoice $invoice, User $actor): ServiceInvoice
    {
        if ($invoice->delivered_at !== null) {
            throw ValidationException::withMessages([
                'delivered_at' => 'سُلّم عمل هذه الفاتورة مسبقاً.',
            ]);
        }

        if ($invoice->status === InvoiceStatusEnum::CANCELLED) {
            throw ValidationException::withMessages([
                'delivered_at' => 'لا يمكن تسليم عمل فاتورة ملغاة.',
            ]);
        }

        if ($invoice->status === InvoiceStatusEnum::RETURNED) {
            throw ValidationException::withMessages([
                'delivered_at' => 'لا يمكن تسليم عمل فاتورة مرتجعة.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $actor) {
            $invoice->update([
                'delivered_at' => now(),
                'delivered_by' => $actor->id,
            ]);

            return $invoice;
        });
    }
}
