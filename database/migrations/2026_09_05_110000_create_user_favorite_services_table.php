<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 76: خدمات مفضّلة لكل موظف تُرفع أعلى قائمة نقطة البيع. جدول ربطٍ
     * بحت بلا أعمدة إضافية: التفضيل حاضرٌ أو غائب لا درجات له.
     */
    public function up(): void
    {
        Schema::create('user_favorite_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_service_id')->constrained('branch_services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'branch_service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorite_services');
    }
};
