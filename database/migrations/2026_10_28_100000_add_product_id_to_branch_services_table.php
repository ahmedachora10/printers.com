<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * منتجٌ يُباع كخدمة: خدمة الفرع المربوطة به — واحدةٌ لكل منتج. منه يُقرأ
     * السعر والتكلفة والحالة، والمخزون يُسحب من صفّ الخامة كأي خدمة.
     */
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->unique()->after('service_template_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
