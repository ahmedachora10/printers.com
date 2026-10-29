<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 208 — رسالةٌ خاصة في المحادثة الداخلية: رمز من تُرسل إليه بصيغة رموز
 * الإشارة (`user:{id}` أو `role:accountant` / `role:branch-admin`). NULL = للجميع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->string('private_to', 40)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->dropColumn('private_to');
        });
    }
};
