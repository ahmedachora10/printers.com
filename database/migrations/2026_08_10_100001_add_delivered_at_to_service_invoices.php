<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تسليم العمل فعلاً للعميل (تاسك 31) — مقابل `delivery_at` الذي هو الموعد
 * المتوقَّع وحده. ختم `delivered_at` هو ما يقلب حالة عمود موعد التسليم إلى
 * «تم تسليم العمل»، و`delivered_by` يحفظ مَن سلّمه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('delivery_at');
            $table->foreignId('delivered_by')->nullable()->after('delivered_at')
                ->constrained('users')->nullOnDelete();
            // قائمة الفواتير تُرشَّح بـ «تم التسليم»، وأمر التذكير يُسقط المُسلَّم.
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropIndex(['delivered_at']);
            $table->dropConstrainedForeignId('delivered_by');
            $table->dropColumn('delivered_at');
        });
    }
};
