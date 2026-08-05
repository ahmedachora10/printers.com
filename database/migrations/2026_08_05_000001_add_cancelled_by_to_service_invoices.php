<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records who cancelled a service invoice and when (تاسك 18): the employee is
 * shown the reason together with the reviewer's name and the cancellation date,
 * which `cancellation_reason` alone could not answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('service_invoices', function (Blueprint $table) use ($sqlite) {
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');

            // SQLite cannot add a foreign key to an existing table.
            if (! $sqlite) {
                $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('service_invoices', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign(['cancelled_by']);
            }

            $table->dropColumn(['cancelled_by', 'cancelled_at']);
        });
    }
};
