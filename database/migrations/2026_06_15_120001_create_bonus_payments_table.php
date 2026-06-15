<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incentive_plan_id')->constrained('incentive_plans')->cascadeOnDelete();
            $table->foreignId('paid_by')->constrained('users');
            $table->decimal('amount', 12, 2);
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            // IMMUTABLE after insert.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_payments');
    }
};
