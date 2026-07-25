<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregated per-line commissions for an agent on a service invoice. Sits next
 * to rebate_amount so the agent's payable is rebate + line commissions, settled
 * together by the same agent_payment_id stamp and gated by invoice status like
 * every other pivot figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoice_agent', function (Blueprint $table) {
            $table->decimal('line_commission_amount', 12, 2)->default(0)->after('rebate_amount');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoice_agent', function (Blueprint $table) {
            $table->dropColumn('line_commission_amount');
        });
    }
};
