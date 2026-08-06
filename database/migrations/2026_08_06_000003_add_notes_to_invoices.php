<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice-level free text for the customer — a remark about the whole order
 * (delivery arrangement, payment terms, a thank-you line) rather than about one
 * item. Distinct from `service_invoice_lines.notes`, which details a single
 * line; both are printed, and the invoice note sits under the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('employee_commission');
        });

        Schema::table('product_invoices', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('product_invoices', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
