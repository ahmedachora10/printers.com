<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Serves the POS lookup: WHERE branch_id + is_active ORDER BY full_name.
            $table->index(['branch_id', 'is_active', 'full_name'], 'customers_branch_active_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_branch_active_name_index');
        });
    }
};
