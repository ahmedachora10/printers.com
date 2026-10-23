<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * عروض الأسعار المستقلة (خدمات ومنتجات) — منفصلة عن الفواتير فلا تدخل
     * مبيعاً ولا عمولة. `data` لقطة حاسبة الفاتورة كما حُسب العرض (الأسطر
     * والإجماليات)، منها يُطبع العرض ويُعاد فتحه في نقطة البيع.
     */
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number');
            $table->string('type');
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('status')->default('new');
            $table->json('data');
            $table->decimal('total_amount', 12, 2);
            $table->text('rejection_reason')->nullable();
            // الفاتورة الناتجة عن التحويل — جدولها يُعرف من `type`.
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'quotation_number']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
