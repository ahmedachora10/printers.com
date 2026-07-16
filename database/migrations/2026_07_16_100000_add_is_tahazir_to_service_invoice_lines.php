<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            // Snapshot the service's tahazir flag at invoice time so the immutable
            // commission ledger can be written from the persisted line — the ledger
            // is now deferred until the invoice is paid (approved).
            $table->boolean('is_tahazir')->default(false)->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('is_tahazir');
        });
    }
};
