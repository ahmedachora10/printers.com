<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_template_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_commission_pct', 5, 2)->default(0);
            $table->decimal('max_discount_pct', 5, 2)->default(0);
            $table->boolean('is_tahazir')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'service_template_id']);
            $table->index('branch_id');
            $table->index('service_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_services');
    }
};
