<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 45: مدير الفرع يضيف خدمة جديدة دون الرجوع للأدمن.
 *
 * القالب صار له مالك: `branch_id = NULL` خدمة عامة يراها كل الفروع (وهو حال كل
 * الخدمات القائمة، فلا تتأثر البيانات)، و`branch_id` مملوءاً خدمةٌ أنشأها مدير
 * فرع فلا تظهر إلا في فرعه وعند السوبر أدمن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_templates', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
