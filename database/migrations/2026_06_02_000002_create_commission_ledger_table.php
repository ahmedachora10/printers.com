<?php

use App\Enums\CommissionSourceTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The commission ledger is append-only: rows are never updated (other than
     * settling `paid_at`) or deleted. Reversals are recorded as new negative rows.
     */
    public function up(): void
    {
        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('branch_id')->constrained('branches');
            $table->unsignedBigInteger('invoice_line_id')->nullable();
            $table->string('invoice_line_type')->nullable();
            $table->decimal('amount', 12, 2);
            $table->boolean('is_tahazir')->default(false);
            $table->tinyInteger('tier_applied')->nullable();
            $table->enum('source_type', CommissionSourceTypeEnum::all())
                ->default(CommissionSourceTypeEnum::STANDARD->value);
            $table->unsignedBigInteger('referral_order_id')->nullable(); // FK added with the internal-referral module
            $table->timestamp('earned_at');
            $table->timestamp('paid_at')->nullable();

            $table->index('user_id');
            $table->index('branch_id');
            $table->index(['invoice_line_type', 'invoice_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledger');
    }
};
