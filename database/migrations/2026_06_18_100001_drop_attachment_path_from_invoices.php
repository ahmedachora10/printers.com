<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt attachments are now handled by the media library (the `receipt`
     * collection on each invoice), so the legacy string column is dropped.
     */
    public function up(): void
    {
        foreach (['product_invoices', 'service_invoices'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('attachment_path');
            });
        }
    }

    public function down(): void
    {
        foreach (['product_invoices', 'service_invoices'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('attachment_path')->nullable();
            });
        }
    }
};
