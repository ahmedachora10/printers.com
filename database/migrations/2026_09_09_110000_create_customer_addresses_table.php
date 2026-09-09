<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 93 — «إمكانية تحديد وحفظ موقع أو عنوان العميل **وربطه بمسافة التوصيل**».
 *
 * دفترُ عناوين لا حقلاً واحداً على `customers`، لثلاثة أسباب:
 *
 *  1. `delivery_zone_id` على العنوان نفسه هو ما يحقّق «ربطه بمسافة التوصيل»
 *     حرفياً: اختيار «مستودع الشركة» يملأ الشريحة والسعر فوراً، بلا مسافةٍ
 *     تُقدَّر بالحدس في كل فاتورة.
 *  2. حقلٌ واحد يُحدَّث تلقائياً كان يدهس عنوان المنزل عند أوّل توصيلٍ للمكتب.
 *     الدفتر يضيف ولا يستبدل.
 *  3. عملاء `corporate` لهم فروع متعدّدة بطبيعتهم، وحقلٌ واحد لا يخدمهم.
 *
 * ولقطةُ العنوان النصّية تبقى على الفاتورة: تعديل الدفتر لاحقاً لا يغيّر
 * فاتورةً طُبعت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // «المنزل»، «المكتب»، «فرع الملقا» — ما يميّزه الكاشير في المنتقي.
            $table->string('label')->nullable();
            $table->text('address');
            $table->string('location_url', 2048)->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
