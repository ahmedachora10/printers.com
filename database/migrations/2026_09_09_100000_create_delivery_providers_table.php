<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 93 — «الاحتفاظ ببيانات السائقين وشركات التوصيل واختيارهم من قائمة جاهزة».
 *
 * كيانٌ مستقل لا مستخدمٌ في النظام: أكثر السائقين وشركات التوصيل بلا حساب،
 * وربطهم بـ`users` كان يفرض إنشاء حساباتٍ لا تُستعمل ولا تُسجَّل الدخول بها.
 *
 * `branch_id` **إلزاميّ**: العميل حسم أن لكل فرع سائقيه وأسعاره، فلا صفوفَ
 * عامّة ولا وراثةَ بين الفروع — بخلاف `payment_methods` التي يجوز فيها الصفّ
 * العامّ (تاسك 59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('driver');
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // فرعان قد يسمّيان سائقيهما بالاسم نفسه؛ ولا يجوز للفرع الواحد أن
            // يكرّر اسماً فيلتبس المنتقي في نقطة البيع.
            $table->unique(['name', 'branch_id']);
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_providers');
    }
};
