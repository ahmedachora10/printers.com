<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المساعدون في الفاتورة: موظفٌ ساعد في الشغل يأخذ عمولته على حصّته بنسبته هو على
 * الخدمة التي اختيرت له، والحصة تُقتطع من أساس عمولة صاحب الفاتورة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_invoice_helpers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('branch_service_id')->constrained();
            $table->string('service_name');
            // amount = مبلغٌ بالريال شاملاً الضريبة، percentage = نسبةٌ من إجمالي الخدمات.
            $table->string('share_type', 20);
            $table->decimal('share_value', 12, 2);
            // الحصة صافيةً من الضريبة — أساس عمولة المساعد وما يُقتطع من صاحب الفاتورة.
            $table->decimal('share_net', 12, 2)->default(0);
            $table->decimal('commission_pct', 5, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->boolean('is_tahazir')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_invoice_helpers');
    }
};
