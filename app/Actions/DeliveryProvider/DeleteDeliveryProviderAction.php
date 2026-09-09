<?php

namespace App\Actions\DeliveryProvider;

use App\Models\DeliveryProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * الحذف ناعم، ويُمنع متى كان المزوّد مذكوراً على فاتورة: بيان التوصيل المطبوع
 * يحمل اسمه، فحذفه يترك فاتورةً تشير إلى لا شيء.
 *
 * فحص العمود قبل الاستعلام: عمود `shipping_provider_id` يصل في الكوميت التالي،
 * فيبقى هذا الحارس صامتاً — لا كاسراً — حتى تصل هجرته.
 */
class DeleteDeliveryProviderAction
{
    public function handle(DeliveryProvider $provider): void
    {
        if ($this->isReferencedByInvoices($provider)) {
            throw ValidationException::withMessages([
                'delivery_provider' => 'لا يمكن حذف المزوّد لأنه مرتبط بفواتير توصيل.',
            ]);
        }

        DB::transaction(fn () => $provider->delete());
    }

    private function isReferencedByInvoices(DeliveryProvider $provider): bool
    {
        if (! Schema::hasColumn('service_invoices', 'shipping_provider_id')) {
            return false;
        }

        return DB::table('service_invoices')
            ->where('shipping_provider_id', $provider->id)
            ->exists();
    }
}
