<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تكلفة الخامات الافتراضية للخدمة: المواد التي يستهلكها المركز لتنفيذها (ورق،
 * فينيل، حبر…). المبلغ للوحدة الواحدة، ويُعبَّأ تلقائياً في نقطة البيع حيث يبقى
 * قابلاً للتعديل. لا علاقة له بـ is_tahazir — ذاك تصنيف للعمولة لا يخصم شيئاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->boolean('has_materials')->default(false)->after('is_tahazir');
            $table->decimal('materials_cost', 12, 2)->default(0)->after('has_materials');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn(['has_materials', 'materials_cost']);
        });
    }
};
