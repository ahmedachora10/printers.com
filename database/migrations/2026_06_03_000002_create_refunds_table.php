<?php

use App\Enums\InvoiceTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            // Discriminator mirroring the originating invoice type.
            $table->enum('source_type', InvoiceTypeEnum::all());
            // Polymorphic link to the originating invoice (ProductInvoice|ServiceInvoice).
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('invoice_type')->nullable();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            // True once the related product stock has been returned to inventory.
            $table->boolean('stock_reversed')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index(['invoice_type', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
