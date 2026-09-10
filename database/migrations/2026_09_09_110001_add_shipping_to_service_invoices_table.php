<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 93 — رسم التوصيل على فاتورة الخدمات.
 *
 * `shipping_fee` **شاملٌ لضريبة القيمة المضافة** كسائر أسعار النظام (تاسك 37)،
 * ويدخل `total_amount` و`vat_amount` ورمزَ ZATCA — فالتوصيل خدمةٌ خاضعة.
 *
 * لكنه **يُستثنى من أساس كل عمولة ومن نقاط الولاء**، وهو نصّ العميل بالأحمر:
 * «احتساب العمولات بعد خصم قيمة التوصيل». الاستثناء في
 * `CalculateServiceInvoiceAction` بفصل `$servicesNet` عن `$netBeforeVat`.
 *
 * الافتراضيّ صفر، فكل فاتورة قائمة تبقى بأرقامها بالحرف: لا هجرة بيانات ولا
 * إعادة حساب ولا أثر على عمولةٍ سبق صرفها.
 *
 * `shipping_address` لقطةٌ نصّية لا مرجع: تعديل دفتر عناوين العميل لاحقاً لا
 * يغيّر ما طُبع على فاتورة. و`customer_address_id` يبقى مرجعاً للتحليل فقط،
 * ويصير null إن حُذف العنوان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->decimal('shipping_fee', 12, 2)->default(0)->after('points_discount');
            $table->foreignId('shipping_provider_id')->nullable()->after('shipping_fee')
                ->constrained('delivery_providers')->nullOnDelete();
            $table->foreignId('shipping_zone_id')->nullable()->after('shipping_provider_id')
                ->constrained('delivery_zones')->nullOnDelete();
            $table->decimal('shipping_distance_km', 8, 2)->nullable()->after('shipping_zone_id');
            $table->foreignId('customer_address_id')->nullable()->after('shipping_distance_km')
                ->constrained('customer_addresses')->nullOnDelete();
            $table->text('shipping_address')->nullable()->after('customer_address_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('shipping_provider_id');
            $table->dropConstrainedForeignKey('shipping_zone_id');
            $table->dropConstrainedForeignKey('customer_address_id');
            $table->dropColumn(['shipping_fee', 'shipping_distance_km', 'shipping_address']);
        });
    }
};
