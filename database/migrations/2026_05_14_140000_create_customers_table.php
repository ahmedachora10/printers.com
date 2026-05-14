<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('phone', 20);
            $table->string('email', 255)->nullable();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('customer_type', 20)->default('individual');
            $table->string('company_name', 255)->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            // agent_id FK will be added in M26 agents migration
            $table->unsignedBigInteger('agent_id')->nullable()->index();
            $table->integer('points_balance')->default(0);
            $table->decimal('cumulative_spend', 12, 2)->default(0);
            $table->string('tier', 20)->default('none');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['phone', 'branch_id'], 'customers_phone_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
