<?php

use App\Models\IncentivePlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 214: كل شريحة تُصرف مستقلّةً — صفٌّ في bonus_payments لكل شريحة، والفهرس
 * الفريد يمنع صرف الشريحة نفسها مرتين.
 *
 * الصفوف القديمة: خطة الشريحة الواحدة = 1، وذات الشرائح صُرفت فيها الأعلى المبلوغة
 * وحدها (تاسك 105) — والخطة مجمَّدة منذ الصرف، فرقمها يُشتقّ من achieved_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonus_payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('tier_number')->default(1)->after('incentive_plan_id');
        });

        IncentivePlan::query()
            ->whereNotNull('tiers')
            ->whereHas('bonusPayments')
            ->each(fn (IncentivePlan $plan) => DB::table('bonus_payments')
                ->where('incentive_plan_id', $plan->id)
                ->update(['tier_number' => $plan->reachedTier()['number'] ?? 1]));

        Schema::table('bonus_payments', function (Blueprint $table) {
            $table->unique(['incentive_plan_id', 'tier_number']);
        });
    }

    public function down(): void
    {
        Schema::table('bonus_payments', function (Blueprint $table) {
            $table->dropUnique(['incentive_plan_id', 'tier_number']);
            $table->dropColumn('tier_number');
        });
    }
};
