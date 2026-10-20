<?php

namespace App\Console\Commands;

use App\Actions\ServiceInvoice\MarkServiceInvoicePaidAction;
use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 191 — أمرٌ لمرّة واحدة: الفواتير المدفوعة جزئياً كُتبت عمولتها كاملةً (تاسك
 * 182)، فتُعاد إلى نسبة المحصَّل بتاريخ آخر دفعة. يُعكس غير المصروف وحده؛ ما صُرف
 * يبقى، والدفعات اللاحقة لا تضيف عمولةً حتى تتجاوز نسبتُها ما صُرف. ما لم يبلغ
 * محصَّلُه الخاماتِ يبقى بلا عمولة كما لو رُفضت دفعته اليوم.
 */
class RebasePartialCommissionsCommand extends Command
{
    protected $signature = 'commissions:rebase-partial
        {--dry-run : اعرض ما سيتغيّر بلا حفظ}';

    protected $description = 'إعادة عمولة الفواتير المدفوعة جزئياً إلى نسبة المحصَّل';

    public function handle(MarkServiceInvoicePaidAction $markPaid): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        ServiceInvoice::query()
            ->where('status', InvoiceStatusEnum::PARTIALLY_PAID)
            ->each(function (ServiceInvoice $invoice) use ($markPaid, $dryRun, &$rows) {
                if ($invoice->commission_earned_at === null && $invoice->minimumPayment() !== null) {
                    return;
                }

                DB::beginTransaction();

                try {
                    $before = round((float) $invoice->ledgerEntries()->sum('amount'), 2);
                    $markPaid->syncCommission($invoice, Date::parse($invoice->payments()->max('paid_at') ?? now()));
                    $after = round((float) $invoice->ledgerEntries()->sum('amount'), 2);
                    $paid = round((float) $invoice->ledgerEntries()->whereNotNull('paid_at')->sum('amount'), 2);

                    $dryRun ? DB::rollBack() : DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();

                    throw $e;
                }

                if ($before !== $after) {
                    $rows[] = [
                        $invoice->invoice_number,
                        number_format($invoice->paidAmount(), 2).' / '.number_format((float) $invoice->total_amount, 2),
                        number_format($before, 2),
                        number_format($after, 2),
                        // العكس لا يتجاوز غير المصروف، فبقاءُ المصروف وحده يعني أنه بلغ النسبة أو تجاوزها.
                        $paid > 0 && $paid >= $after ? 'صُرف '.number_format($paid, 2).' — لا يُستردّ' : '',
                    ];
                }
            });

        if ($rows === []) {
            $this->info('لا فواتير مدفوعة جزئياً تتغيّر عمولتها.');

            return self::SUCCESS;
        }

        $this->table(['الفاتورة', 'المحصَّل / الإجمالي', 'العمولة قبل', 'العمولة بعد', 'ملاحظة'], $rows);

        $dryRun
            ? $this->warn('تشغيل تجريبي — لم يُحفظ شيء. أعد التشغيل بلا ‎--dry-run‎ للتنفيذ.')
            : $this->info('أُعيدت عمولة '.count($rows).' فاتورة إلى نسبة المحصَّل.');

        return self::SUCCESS;
    }
}
