<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('service_invoices')->cascadeOnDelete();
            $table->foreignId('branch_service_id')->nullable()->constrained('branch_services')->nullOnDelete();
            $table->string('service_name');
            $table->integer('qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('commission_pct', 5, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->tinyInteger('tier_applied')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('branch_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_invoice_lines');
    }
};
