<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 82: ترتيب يدوي لقوالب الخدمات على نمط `sort_order` في دليل الخدمات
     * (M19). التعبئة الأوّلية بترتيب الاسم الحالي حتى لا تبدأ كل الصفوف من صفر
     * متساوية فيظلّ الترتيب عشوائياً حتى أوّل إعادة ترتيب يدوية.
     */
    public function up(): void
    {
        Schema::table('service_templates', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('description')->index();
        });

        $order = 0;
        foreach (DB::table('service_templates')->orderBy('name')->pluck('id') as $id) {
            DB::table('service_templates')->where('id', $id)->update(['sort_order' => ++$order]);
        }
    }

    public function down(): void
    {
        Schema::table('service_templates', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
