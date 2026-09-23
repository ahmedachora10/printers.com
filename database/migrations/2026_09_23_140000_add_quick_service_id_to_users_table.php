<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 118: الخدمة الافتراضية الثابتة في شاشة الفاتورة السريعة — تفضيلٌ
     * شخصيّ للموظف، يُحدَّد تلقائياً كلما فُتحت الشاشة.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('quick_service_id')->nullable()->constrained('branch_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quick_service_id');
        });
    }
};
