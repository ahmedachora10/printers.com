<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 228: اشتراك الفرع. الثلاثة null = فرعٌ بلا اشتراك محدَّد (لا عدّاد ولا قيود).
 * المدة (سنة/سنتان) لا تُخزَّن — تملأ النهاية في النموذج فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->date('subscription_starts_at')->nullable()->after('vat_rate_override');
            $table->date('subscription_ends_at')->nullable()->after('subscription_starts_at');
            $table->date('subscription_grace_until')->nullable()->after('subscription_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['subscription_starts_at', 'subscription_ends_at', 'subscription_grace_until']);
        });
    }
};
