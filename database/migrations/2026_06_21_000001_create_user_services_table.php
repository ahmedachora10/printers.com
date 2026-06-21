<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_service_id')->constrained('branch_services')->cascadeOnDelete();
            // Per-employee commission rate for this service. A row's presence means
            // the employee has an explicit rate; with no row the employee earns 0%
            // commission for the service (zero fallback, not the branch base rate).
            $table->decimal('commission_override_pct', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'branch_service_id']);
            $table->index('branch_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_services');
    }
};
