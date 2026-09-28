<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 135 — طلب استرجاع فاتورة خدمة يرفعه الموظف متى حُصِّل منها مبلغ،
 * ويعتمده (باختيار طريقة الردّ) المحاسب أو مدير الفرع أو المدير العام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained('service_invoices');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('requested_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('refund_id')->nullable()->constrained('refunds');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_return_requests');
    }
};
