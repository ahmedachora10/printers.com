<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->nullable()->after('id');
            $table->string('phone', 20)->nullable()->after('name');
            $table->foreignId('branch_id')->nullable()->after('phone')
                ->constrained('branches')->nullOnDelete();
            $table->decimal('salary', 12, 2)->default(0)->after('branch_id');
            $table->decimal('base_commission_pct', 5, 2)->default(0)->after('salary');
            $table->decimal('referral_commission_pct', 5, 2)->default(0)->after('base_commission_pct');
            $table->date('joined_date')->nullable()->after('referral_commission_pct');
            $table->boolean('is_active')->default(true)->after('joined_date');
            $table->softDeletes()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn([
                'username',
                'phone',
                'branch_id',
                'salary',
                'base_commission_pct',
                'referral_commission_pct',
                'joined_date',
                'is_active',
                'deleted_at',
            ]);
        });
    }
};
