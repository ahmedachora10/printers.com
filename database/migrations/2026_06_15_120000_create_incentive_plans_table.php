<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incentive_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->decimal('target_amount', 12, 2);
            $table->enum('bonus_type', ['fixed', 'percentage']);
            $table->decimal('bonus_value', 12, 2);
            $table->decimal('achieved_amount', 12, 2)->default(0);
            $table->enum('status', ['active', 'achieved', 'missed', 'paid'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            // One incentive plan per employee per month.
            $table->unique(['user_id', 'period_month', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incentive_plans');
    }
};
