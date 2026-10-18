<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 187 — حسابات الشركة البنكية لكل فرع، ومصروف «تحويل بنكي من حساب
     * الشركة» يحمل الحساب الذي خرج منه. المصروفات التحويلية القديمة تبقى بلا حساب.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('iban_last4', 4)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('paid_from')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });

        Schema::dropIfExists('bank_accounts');
    }
};
