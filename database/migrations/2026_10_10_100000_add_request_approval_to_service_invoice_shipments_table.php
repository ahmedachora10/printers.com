<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توصيلٌ يُطلب بعد اعتماد الفاتورة: يسجّله أيّ مستخدم ويعتمده المحاسب.
 *
 * الطلب المنتظر = `requested_by` بلا `approved_at`، ولا يدخل في رسم الفاتورة ولا
 * في كشف التوصيل حتى يُعتمد. طلبات نقطة البيع لا `requested_by` لها فتبقى كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoice_shipments', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('fee')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoice_shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
