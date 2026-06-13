<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('supplier_name')->nullable();
            $table->string('receipt_reference')->nullable();
            $table->string('receipt_path')->nullable();
            $table->text('comment')->nullable();
            $table->date('date');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
