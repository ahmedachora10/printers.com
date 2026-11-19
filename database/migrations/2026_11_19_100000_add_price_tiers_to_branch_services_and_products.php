<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 241 — شرائح تسعير حسب الكمية: [{to, price, min_price, max_price}] مرتّبةً بـ«إلى»،
 * والأخيرة قد تكون بلا «إلى». null = بلا شرائح كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->json('price_tiers')->nullable()->after('price_per_sqm');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('price_tiers')->nullable()->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', fn (Blueprint $table) => $table->dropColumn('price_tiers'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('price_tiers'));
    }
};
