<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Square-meter pricing for area-based services (banners, stickers). A branch
 * service priced 'sqm' derives its POS unit price from entered dimensions
 * (width × height in cm) at price_per_sqm; agent_commission_per_sqm feeds the
 * per-sqm line commission type on the service POS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->string('pricing_type', 10)->default('unit')->after('max_discount_pct');
            $table->decimal('price_per_sqm', 12, 2)->default(0)->after('pricing_type');
            $table->decimal('agent_commission_per_sqm', 12, 2)->default(0)->after('price_per_sqm');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'price_per_sqm', 'agent_commission_per_sqm']);
        });
    }
};
