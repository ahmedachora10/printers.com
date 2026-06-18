<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // When true, an invoice settled with this method (e.g. bank transfer)
            // must carry an uploaded receipt — stored via the media library.
            $table->boolean('requires_attachment')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('requires_attachment');
        });
    }
};
