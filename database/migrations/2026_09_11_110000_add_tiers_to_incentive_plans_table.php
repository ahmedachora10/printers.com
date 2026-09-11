<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 105 — شرائح متعددة لخطة الحوافز: [{threshold, value}] مرتّبة بالعتبة.
 *
 * NULL = خطة بشريحة واحدة هي (target_amount, bonus_value) — كل خطة قائمة — فلا
 * هجرة بيانات ولا يتغيّر حافزٌ قائم. وأدنى شريحة تُنسخ دائماً إلى العمودين،
 * فكل قارئ لـ«الهدف» يبقى صحيحاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incentive_plans', function (Blueprint $table) {
            $table->json('tiers')->nullable()->after('bonus_value');
        });
    }

    public function down(): void
    {
        Schema::table('incentive_plans', function (Blueprint $table) {
            $table->dropColumn('tiers');
        });
    }
};
