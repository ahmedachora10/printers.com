<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use App\Notifications\UpcomingDeliveryNotification;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * يُذكِّر كل موظف بفواتيره التي يحلّ موعد تسليم عملها غداً — يُشغَّل يومياً.
 *
 * التذكير يُرسل مرة واحدة لكل فاتورة: إعادة تشغيل الأمر في اليوم نفسه لا تُكرّره،
 * إذ تُستبعد الفواتير التي سبق أن حمل إشعارٌ معرّفها.
 */
class NotifyUpcomingDeliveriesCommand extends Command
{
    protected $signature = 'invoices:notify-upcoming-deliveries';

    protected $description = 'تذكير الموظفين بمواعيد تسليم فواتيرهم المستحقة غداً';

    public function handle(): int
    {
        // now() هنا CarbonImmutable على مستوى التطبيق — addDay() تُعيد نسخة جديدة
        // ولا تُعدّل الأصل، فلا بد من الإسناد.
        $tomorrow = now()->addDay();

        $invoices = ServiceInvoice::query()
            ->whereNotNull('delivery_at')
            ->whereDate('delivery_at', $tomorrow->toDateString())
            ->whereNotIn('status', [InvoiceStatusEnum::CANCELLED->value, InvoiceStatusEnum::RETURNED->value])
            ->whereNotNull('user_id')
            ->with('user')
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('لا توجد مواعيد تسليم غداً.');

            return self::SUCCESS;
        }

        $alreadyNotified = DatabaseNotification::query()
            ->where('type', UpcomingDeliveryNotification::class)
            ->whereIn('data->invoiceId', $invoices->pluck('id')->all())
            ->pluck('data')
            ->map(fn ($data) => (int) ($data['invoiceId'] ?? 0))
            ->all();

        $sent = 0;

        foreach ($invoices as $invoice) {
            if ($invoice->user === null || in_array((int) $invoice->id, $alreadyNotified, true)) {
                continue;
            }

            $invoice->user->notify(new UpcomingDeliveryNotification(
                $invoice->invoice_number,
                (int) $invoice->id,
                $invoice->delivery_at->format('Y-m-d H:i'),
            ));

            $sent++;
        }

        $this->info("تم إرسال {$sent} تذكيراً بموعد التسليم.");

        return self::SUCCESS;
    }
}
