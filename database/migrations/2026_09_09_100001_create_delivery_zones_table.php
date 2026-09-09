<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 93 — «إنشاء شرائح متعددة لأسعار التوصيل حسب المسافة أو المنطقة».
 *
 * نوعان في جدولٍ واحد بعمود `type` (انظر `DeliveryZoneTypeEnum`):
 *
 *  - حيّ:   name='حي النرجس'، from_km=null، to_km=null، price=25
 *  - مسافة: name='من 0 إلى 5 كم'، from_km=0، to_km=5، price=20
 *  - مفتوحة: name='أكثر من 20 كم'، from_km=20، to_km=null، price=60
 *
 * `from_km`/`to_km` قابلان للإفراغ لأن صفّ الحيّ لا يقيس مسافةً أصلاً، ولأن
 * الشريحة الأخيرة مفتوحة بطبيعتها. والتحقّق يُلزم `from_km` لصفوف المسافة
 * ويُفرغهما لصفوف الأحياء، فلا يخزَّن صفٌّ نصفَ معرَّف.
 *
 * `branch_id` إلزاميّ: لكل فرع شرائحه وأسعاره بنصّ قرار العميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('distance');
            $table->string('name');
            $table->decimal('from_km', 8, 2)->nullable();
            $table->decimal('to_km', 8, 2)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'branch_id']);
            // منتقي نقطة البيع يقرأ بهذا الترتيب: شرائح الفرع النشطة مرتّبةً.
            $table->index(['branch_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
    }
};
