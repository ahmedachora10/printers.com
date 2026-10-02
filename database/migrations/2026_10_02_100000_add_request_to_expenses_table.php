<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 157 — طلب مصروف من الموظف. requested_by غير فارغ = طلب، ولا يُحسب حتى
     * يقبله المحاسب (accepted_at). القبول غير الاعتماد (approved_at، قفلٌ للمديرين).
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('requested_by');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn('accepted_at');
            $table->dropConstrainedForeignId('requested_by');
        });
    }
};
