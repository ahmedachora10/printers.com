<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعات الفاتورة: العربون وما يليه من دفعات على فاتورة واحدة (منتجات أو خدمات).
 *
 * الجدول للإضافة فقط — على نمط commission_ledger: لا UPDATE ولا DELETE بعد الإدراج.
 * تصحيح دفعة خاطئة يكون بإدراج دفعة سالبة مقابلة، فيبقى أثر التحصيل كاملاً وقابلاً
 * للمراجعة. لذلك لا يحمل الجدول deleted_at، والمبلغ يقبل الإشارة السالبة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            // فاتورة منتجات أو خدمات — يُخزَّن اسم الصنف كاملاً (لا morph map في المشروع).
            $table->morphs('invoice');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            // طريقة دفع كل دفعة على حدة: قد يُدفع العربون نقداً والباقي تحويلاً.
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            // تقرير المبيعات والتقرير اليومي يقرآن الإيراد المحقق بالفرع وتاريخ التحصيل.
            $table->index(['branch_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
