<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * موعد تسليم العمل للعميل: تاريخ ووقت اختياريان يُلتقطان عند إصدار فاتورة الخدمة،
 * فتعرف قائمة الفواتير ما تأخّر عن موعده وما يُسلَّم اليوم، ويُذكَّر الموظف صاحب
 * الفاتورة قبل الموعد بيوم. خاص بفواتير الخدمات — بيع المنتجات تسليمه فوري.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->timestamp('delivery_at')->nullable()->after('notes');
            // القائمة تُرشَّح بـ «تسليم اليوم / متأخر» وأمر التذكير يمسح يوماً واحداً.
            $table->index('delivery_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropIndex(['delivery_at']);
            $table->dropColumn('delivery_at');
        });
    }
};
