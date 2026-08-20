<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 50 (وشقّ المخزون من تاسك 54): ربط خامات الخدمة بالمخزون. كانت «تكلفة
 * الخامات» رقماً محاسبياً يدوياً لا يمسّ المخزون إطلاقاً؛ هنا تُعرَّف الخامة
 * منتجاً حقيقياً بكمية استهلاك لكل وحدة من الخدمة، فيخصمها اعتمادُ الفاتورة من
 * المخزون ويعيدها استرجاعُها.
 *
 * `qty_per_unit` عشري لأن خامة البنر تُستهلك بالمتر المربع (تاسك 51)، والوحدة
 * المقصودة هي **الكمية المحاسَب عليها في السطر**: عدد القطع لخدمة بالقطعة،
 * ومساحة السطر بالمتر المربع لخدمة مسعّرة بالمتر — فمترٌ من الفينيل لكل متر مبيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_service_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_service_id')->constrained('branch_services')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_per_unit', 12, 2);
            $table->timestamps();

            $table->unique(['branch_service_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_materials');
    }
};
