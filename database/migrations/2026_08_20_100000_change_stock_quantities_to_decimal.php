<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 51: المخزون بالمتر المربع. كانت كل كميات المخزون `integer`، فبيع «٠٫٥ م²»
 * مستحيل — تُدوَّر إلى عدد صحيح. هذه الهجرة توسّع نوع العمود وحده على السلسلة
 * كاملها: حركات المخزون، سطور فاتورة المنتجات، الرصيد المحسوب والحد الأدنى،
 * سطور الجرد، وكميات أوامر الشراء (وإلا استُلمت خامةُ المتر بأعداد صحيحة فقط).
 *
 * ⚠️ `stock_movements` جدول إدراج فقط — لا يُصحَّح صفٌّ فيه ولا يُحذف. التغيير هنا
 * على **النوع** لا على الصفوف: القيم القائمة تبقى كما هي وتُقرأ ‎12 → ‎12.00،
 * ومجموعها (وهو `products.current_stock`) لا يتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // signed: inbound positive, outbound negative
            $table->decimal('qty', 12, 2)->change();
        });

        Schema::table('product_invoice_lines', function (Blueprint $table) {
            $table->decimal('qty', 12, 2)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('min_stock_level', 12, 2)->default(0)->change();
            $table->decimal('current_stock', 12, 2)->default(0)->change();
        });

        Schema::table('stock_reconciliation_lines', function (Blueprint $table) {
            $table->decimal('system_qty', 12, 2)->change();
            $table->decimal('physical_qty', 12, 2)->change();
            $table->decimal('variance', 12, 2)->change();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->decimal('ordered_qty', 12, 2)->change();
            $table->decimal('received_qty', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('qty')->change();
        });

        Schema::table('product_invoice_lines', function (Blueprint $table) {
            $table->integer('qty')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('min_stock_level')->default(0)->change();
            $table->integer('current_stock')->default(0)->change();
        });

        Schema::table('stock_reconciliation_lines', function (Blueprint $table) {
            $table->integer('system_qty')->change();
            $table->integer('physical_qty')->change();
            $table->integer('variance')->change();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->integer('ordered_qty')->change();
            $table->integer('received_qty')->default(0)->change();
        });
    }
};
