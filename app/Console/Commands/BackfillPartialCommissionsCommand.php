<?php

namespace App\Console\Commands;

use App\Actions\ServiceInvoice\MarkServiceInvoicePaidAction;
use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 182 — أمرٌ لمرّة واحدة: الفواتير المدفوعة جزئياً قبل القاعدة الجديدة، التي
 * بلغ محصَّلها حدَّ commissionFloor()، تُكتب عمولتها الآن بتاريخ الدفعة التي بلغته.
 * ما لم يبلغ الحدّ يبقى ينتظر دفعته كما تنتظر أيّ فاتورة جديدة.
 */
class BackfillPartialCommissionsCommand extends Command
{
    protected $signature = 'commissions:backfill-partial
        {--dry-run : اعرض ما سيُكتب بلا حفظ}';

    protected $description = 'كتابة عمولة الفواتير المدفوعة جزئياً التي بلغ محصَّلها حدّ الخامات والعمولات والضريبة';

    public function handle(MarkServiceInvoicePaidAction $markPaid): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        ServiceInvoice::query()
            ->where('status', InvoiceStatusEnum::PARTIALLY_PAID)
            ->whereNull('commission_earned_at')
            ->with(['payments' => fn ($q) => $q->orderBy('paid_at')->orderBy('id')])
            ->each(function (ServiceInvoice $invoice) use ($markPaid, $dryRun, &$rows) {
                $floor = min($invoice->commissionFloor(), round((float) $invoice->total_amount, 2));
                $running = 0.0;
                $reachedAt = null;

                foreach ($invoice->payments as $payment) {
                    $running = round($running + (float) $payment->amount, 2);

                    if ($running >= $floor) {
                        $reachedAt = $payment->paid_at;
                        break;
                    }
                }

                if ($reachedAt === null) {
                    return;
                }

                if (! $dryRun) {
                    DB::transaction(function () use ($invoice, $markPaid, $reachedAt) {
                        $locked = ServiceInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                        if ($locked->commission_earned_at === null && $locked->status === InvoiceStatusEnum::PARTIALLY_PAID) {
                            $markPaid->earnCommission($locked, $reachedAt);
                        }
                    });
                }

                $rows[] = [$invoice->invoice_number, number_format($floor, 2), number_format($invoice->paidAmount(), 2), $reachedAt->format('d/m/Y')];
            });

        if ($rows === []) {
            $this->info('لا فواتير مدفوعة جزئياً بلغت حدّها — لا شيء يُكتب.');

            return self::SUCCESS;
        }

        $this->table(['الفاتورة', 'الحدّ', 'المحصَّل', 'تاريخ الاستحقاق'], $rows);

        $dryRun
            ? $this->warn('تشغيل تجريبي — لم يُكتب شيء. أعد التشغيل بلا ‎--dry-run‎ للتنفيذ.')
            : $this->info('كُتبت عمولة '.count($rows).' فاتورة.');

        return self::SUCCESS;
    }
}
