<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text detail the employee may attach to a single service line at the POS
 * (measurements, paper stock, delivery notes…). Printed on the customer's
 * invoice, so it is customer-facing rather than an internal remark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('service_name');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
