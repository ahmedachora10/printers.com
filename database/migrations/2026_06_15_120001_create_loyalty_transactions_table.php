<?php

use App\Enums\LoyaltyTransactionTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Polymorphic link to the originating invoice (nullable for manual
            // adjustments / expiry). FK omitted by design — invoice_type varies.
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('invoice_type')->nullable();
            $table->enum('type', LoyaltyTransactionTypeEnum::all());
            $table->integer('points');
            $table->integer('balance_after');
            $table->text('notes')->nullable();
            // Append-only ledger: a single created_at, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('customer_id');
            $table->index(['invoice_type', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
