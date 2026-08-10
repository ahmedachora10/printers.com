<?php

namespace App\Actions\Report;

use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoiceLine;
use Illuminate\Database\Query\Builder;

/**
 * القاعدة المشتركة لمعاملة عمولة الفاتورة المرتجعة (تاسك 10)، يستدعيها تقرير
 * العمولات والتقرير اليومي معاً حتى لا يعطيا رقمين مختلفين لليوم نفسه.
 *
 * الاسترجاع يكتب صفاً سالباً مقابلاً بدل تعديل الـ ledger (فهو جدول للإضافة
 * فقط)، فالمقاصّة وحدها لا تستقيم إلا إذا صادف أن نافذة التقرير ضمّت الاستحقاق
 * والاسترجاع معاً. إسقاط الصفّين معاً يجعل «المستحق» صحيحاً في كل نافذة.
 *
 * أما ما **صُرِف** فعلاً فيبقى محسوباً: ذلك مال خرج من الصندوق ولا يُسترد (M14).
 */
class ExcludeReturnedCommission
{
    /**
     * Drop the unpaid ledger rows of returned service invoices from a
     * commission_ledger query, in place.
     *
     * @param  Builder  $query  a query already rooted at commission_ledger
     */
    public static function apply(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q
                ->whereNotNull('commission_ledger.paid_at')
                ->orWhereNotExists(fn (Builder $sub) => self::returnedInvoice($sub)),
        );
    }

    /**
     * Correlated subquery: does this ledger row belong to a returned invoice?
     * Written as EXISTS rather than a join so it composes with every caller.
     */
    public static function returnedInvoice(Builder $query): Builder
    {
        return $query->selectRaw('1')
            ->from('service_invoice_lines')
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_lines.invoice_id')
            ->whereColumn('service_invoice_lines.id', 'commission_ledger.invoice_line_id')
            ->where('commission_ledger.invoice_line_type', ServiceInvoiceLine::class)
            ->where('service_invoices.status', InvoiceStatusEnum::RETURNED->value);
    }
}
