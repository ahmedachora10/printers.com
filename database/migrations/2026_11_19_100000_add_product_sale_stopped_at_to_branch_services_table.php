<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «إيقاف البيع كخدمة»: الخدمة المربوطة بالمنتج لا تُحذف — فواتيرها القديمة
     * وعمولاتها تشير إليها — بل يُختم إيقافها، وإعادة التحويل تمحو الختم.
     */
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->timestamp('product_sale_stopped_at')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('product_sale_stopped_at');
        });
    }
};
