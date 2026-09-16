<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 111 — تسوية أجر السائق مصروفٌ مربوطٌ بالطلب (service_invoice_id) وبسائقه.
     * وجود delivery_provider_id هو ما يجعل المصروف «تسوية توصيل»: لا جدول ثانٍ
     * يحمل المبلغ والمصدر ومَن ومتى مرّةً أخرى.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('delivery_provider_id')->nullable()->after('service_invoice_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_provider_id');
        });
    }
};
