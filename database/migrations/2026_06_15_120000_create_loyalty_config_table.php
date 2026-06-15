<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('earning_rate', 8, 4)->default(1);
            $table->decimal('redemption_rate', 8, 4)->default(100);
            $table->integer('min_redemption_points')->default(500);
            $table->decimal('bronze_threshold', 12, 2)->default(500);
            $table->decimal('silver_threshold', 12, 2)->default(2000);
            $table->decimal('gold_threshold', 12, 2)->default(5000);
            $table->decimal('bronze_discount_pct', 5, 2)->default(2);
            $table->decimal('silver_discount_pct', 5, 2)->default(5);
            $table->decimal('gold_discount_pct', 5, 2)->default(8);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_config');
    }
};
