<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 112 — ربط المصروف بفاتورة خدمات. والمرفق صار Media Library، فيُحذف
     * `receipt_path` الذي لم يكتبه شيءٌ منذ M16 — إلا إن وُجدت فيه بيانات.
     */
    public function up(): void
    {
        throw_if(DB::table('expenses')->whereNotNull('receipt_path')->exists(), RuntimeException::class, 'expenses.receipt_path has data — migrate it before dropping.');

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('service_invoice_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->dropColumn('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_invoice_id');
            $table->string('receipt_path')->nullable();
        });
    }
};
