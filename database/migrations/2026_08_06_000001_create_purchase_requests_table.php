<?php

use App\Enums\PurchaseRequestStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->enum('status', PurchaseRequestStatusEnum::all())->default(PurchaseRequestStatusEnum::PENDING->value);
            $table->text('notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('status');
            $table->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
