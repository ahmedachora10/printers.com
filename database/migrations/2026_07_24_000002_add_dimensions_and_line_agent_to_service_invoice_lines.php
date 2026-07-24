<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line dimensions (sqm-priced services snapshot the entered width/height in
 * cm) and the optional per-line commission owner: an agent picked per service
 * line with a snapshot of the commission terms (type + value) and the computed
 * amount. The amount never changes the customer's total — it is aggregated onto
 * the service_invoice_agent pivot as a payable settled with agent payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('service_invoice_lines', function (Blueprint $table) use ($sqlite) {
            $table->decimal('width_cm', 8, 2)->nullable()->after('unit_price');
            $table->decimal('height_cm', 8, 2)->nullable()->after('width_cm');
            $table->unsignedBigInteger('agent_id')->nullable()->after('tier_applied');
            $table->string('agent_commission_type', 20)->nullable()->after('agent_id');
            $table->decimal('agent_commission_value', 12, 2)->nullable()->after('agent_commission_type');
            $table->decimal('agent_commission_amount', 12, 2)->default(0)->after('agent_commission_value');

            $table->index('agent_id');

            if (! $sqlite) {
                $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('service_invoice_lines', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign(['agent_id']);
            }
            $table->dropIndex(['agent_id']);
            $table->dropColumn([
                'width_cm',
                'height_cm',
                'agent_id',
                'agent_commission_type',
                'agent_commission_value',
                'agent_commission_amount',
            ]);
        });
    }
};
