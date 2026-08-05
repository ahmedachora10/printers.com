<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ready-made detail phrases for a branch service ("طباعة وجهين", "تغليف حراري").
 * A branch admin sets them per service; the service POS joins them into the
 * placeholder of that line's free-text detail box, so the cashier sees what is
 * usually written for this service instead of a generic hint. Advisory only —
 * nothing is copied into service_invoice_lines.notes unless typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->json('note_examples')->nullable()->after('agent_commission_per_sqm');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('note_examples');
        });
    }
};
