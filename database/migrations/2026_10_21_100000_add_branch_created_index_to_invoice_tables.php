<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قائمة الفواتير تُرشَّح بالفرع ومدى تاريخ الإنشاء معاً، فالفهرس المركّب يغني
 * عن مسح كل فواتير الفرع. فهرس branch_id المفرد يبقى: يسند المفتاح الأجنبي في MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['service_invoices', 'product_invoices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->index(['branch_id', 'created_at']));
        }
    }

    public function down(): void
    {
        foreach (['service_invoices', 'product_invoices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex(['branch_id', 'created_at']));
        }
    }
};
