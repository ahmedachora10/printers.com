<?php

namespace App\Actions\Coupon;

use App\Models\Coupon;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;

/**
 * ردّ سعة الكوبون التي استهلكتها فاتورةٌ فُكّ أثرها — إلغاءً أو تعديلاً أو
 * استرجاعاً كاملاً. قاعدةٌ واحدة يستدعيها مسارا الاسترجاع كلاهما (استرجاع الموظف
 * عبر ReversesServiceInvoiceAccruals، ومرتجع المحاسب عبر CreateRefundAction)،
 * ولنوعَي الفاتورة معاً — فاتورة المنتجات تحمل كوبوناً كما تحمله فاتورة الخدمة.
 *
 * تُكتب كدالة ساكنة لأنّ من يستدعيها سمةٌ (trait) لا تملك حاقن تبعيات، على منوال
 * ExcludeReturnedCommission.
 */
class ReleaseCouponCapacity
{
    /**
     * Give a coupon's capacity back (never below zero). No-op for an invoice
     * that carried no coupon.
     */
    public static function apply(ProductInvoice|ServiceInvoice $invoice): void
    {
        if ($invoice->coupon_id === null) {
            return;
        }

        Coupon::query()
            ->whereKey($invoice->coupon_id)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
    }
}
