<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 51: منتج يُباع بالمتر المربع (فينيل، ستيكر، لوحة…). القرار المتفق عليه هو
 * تجربة الخدمة نفسها — «مقاسان يُضربان» لا كمية عشرية حرة: نقطة البيع تطلب العرض
 * والطول وعدد القطع، وتُشتقّ الكمية = (العرض/100)×(الطول/100)×عدد القطع، وتُخزَّن
 * على السطر مع المقاسين. و`selling_price` لمثل هذا المنتج هو **سعر المتر المربع**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_sqm')->default(false)->after('unit_id');
        });

        Schema::table('product_invoice_lines', function (Blueprint $table) {
            // لقطة المقاس وعدد القطع كما بيعت — تُطبع على الفاتورة وتشرح من أين
            // جاءت الكمية العشرية. تبقى null لسطر المنتج المسعّر بالقطعة.
            $table->decimal('width_cm', 12, 2)->nullable()->after('qty');
            $table->decimal('height_cm', 12, 2)->nullable()->after('width_cm');
            $table->unsignedInteger('pieces')->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_sqm');
        });

        Schema::table('product_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['width_cm', 'height_cm', 'pieces']);
        });
    }
};
