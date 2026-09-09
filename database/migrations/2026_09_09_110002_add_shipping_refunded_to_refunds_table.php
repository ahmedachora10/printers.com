<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 93 — قرار المحاسب في ردّ قيمة التوصيل، مكتوباً على المرتجع نفسه.
 *
 * العميل حسم أن الردّ **قرارُ المحاسب في كل حالة**: السائق قد يكون ذهب وتكبّد
 * الوقود، وقد يكون الطلب لم يُشحن أصلاً.
 *
 * ولا يكفي استنتاج حصّة الشحن نسبياً من مبلغ المرتجع: الاستنتاج يخالف قرار
 * المحاسب في كل مرّة — فإمّا يطرح من الإنفاق التراكمي شحناً لم يُردّ، أو يُبقي
 * فيه شحناً رُدّ. القرار يُكتب صراحةً ويُقرأ صراحةً.
 *
 * الافتراضيّ صفر، فكل مرتجع قائم يبقى كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // كم من `amount` كان ردّاً لقيمة التوصيل — جزءٌ منه لا إضافةٌ عليه.
            $table->decimal('shipping_refunded', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('shipping_refunded');
        });
    }
};
