<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('total_invoices');
            $table->decimal('total_rebate', 12, 2);
            $table->foreignId('paid_by')->constrained('users');
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            // IMMUTABLE after insert.
        });

        // Mark which invoices a rebate payment covers, so a rebate is never paid
        // twice regardless of overlapping periods.
        foreach (['product_invoices', 'service_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('agent_payment_id')->nullable()->index()->after('agent_rebate');
            });
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            foreach (['product_invoices', 'service_invoices'] as $name) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreign('agent_payment_id')->references('id')->on('agent_payments')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['product_invoices', 'service_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->dropForeign(['agent_payment_id']);
                }
                $table->dropColumn('agent_payment_id');
            });
        }

        Schema::dropIfExists('agent_payments');
    }
};
