<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('product_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->integer('qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_invoice_lines');
    }
};
