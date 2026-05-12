<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 12, 2);
            $table->integer('capacity')->nullable();
            $table->integer('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code', 'branch_id'], 'coupons_code_branch_unique');
            $table->index('branch_id');
            $table->index('is_active');
            $table->index('expires_at');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
