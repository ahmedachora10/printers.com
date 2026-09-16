<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** تاسك 113 — اعتماد المصروف. الحالة مشتقّة: approved_at غير فارغ = معتمد. */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('date');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->index(['branch_id', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'approved_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
