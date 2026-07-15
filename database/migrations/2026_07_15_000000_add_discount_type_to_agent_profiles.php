<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            // Whether `rate` is read as a percentage of the invoice or a flat
            // SAR amount — mirrors the coupon discount_type concept.
            $table->string('discount_type', 20)->default('percentage')->after('discount_mode');

            // A fixed rate can be any SAR amount, so widen beyond the old
            // percentage-only decimal(5,2) to the standard money precision.
            $table->decimal('rate', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn('discount_type');
            $table->decimal('rate', 5, 2)->default(0)->change();
        });
    }
};
