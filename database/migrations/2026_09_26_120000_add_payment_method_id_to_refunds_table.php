<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 131 — طريقة ردّ المبلغ للعميل، مكتوبةً على المرتجع نفسه.
 *
 * بدونها كان المرتجع يُنسب في تقرير المبيعات إلى طريقة رأس الفاتورة، وهي null
 * لكل فاتورة سُدِّدت بعربون ودفعات ⇒ يُطرح من «غير محدد». القديم يبقى null
 * ويُنسب كما كان إلى طريقة الفاتورة (COALESCE في التقرير).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('shipping_refunded')
                ->constrained('payment_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
