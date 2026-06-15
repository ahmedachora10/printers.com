<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_invoices', 'service_invoices'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // discount mode: amount deducted from the invoice (reduces total).
                $table->decimal('agent_discount', 12, 2)->default(0)->after('coupon_discount');
                // rebate mode: amount owed to the agent; recorded, NOT deducted.
                $table->decimal('agent_rebate', 12, 2)->default(0)->after('agent_discount');
            });
        }
    }

    public function down(): void
    {
        foreach (['product_invoices', 'service_invoices'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['agent_discount', 'agent_rebate']);
            });
        }
    }
};
