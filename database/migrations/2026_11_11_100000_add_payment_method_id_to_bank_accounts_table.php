<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 234 — طريقة الدفع المقابلة للحساب البنكي: بها يُنسب مصروف التحويل إلى
     * صفّ طريقته في «المبيعات حسب طريقة الدفع». الحسابات القائمة تبقى بلا ربط حتى تُربط.
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
