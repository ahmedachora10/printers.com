<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 67: كمية سطر طلب الشراء الداخلي تتبع وحدة المنتج — قطعة أو متر مربع.
 *
 * هجرة 2026_08_20_100000 حوّلت كل كميات المخزون إلى `DECIMAL(12,2)` وتخطّت
 * طلبات الشراء الداخلية وحدها (سبقتها بأسبوعين)، فبقي `qty` عدداً صحيحاً يبتر
 * «7.1 م²» إلى 7 صامتاً. وهنا يُوسَّع النوع، ويُلتقط `is_sqm` **لقطةً** وقت
 * الطلب لا قراءةً حيّة من المنتج: تصنيف المنتج قد يتبدّل لاحقاً، والطلب القديم
 * يبقى مقروءاً بالوحدة التي كُتب بها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->decimal('qty', 12, 2)->change();
            $table->boolean('is_sqm')->default(false)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->dropColumn('is_sqm');
            $table->integer('qty')->change();
        });
    }
};
