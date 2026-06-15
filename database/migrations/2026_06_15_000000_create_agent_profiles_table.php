<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An "agent" is a user holding the `agent` role. This table holds the
        // B2B-specific profile fields that only apply to agent users.
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('agent_type', 20)->default('individual');
            $table->string('discount_mode', 20)->default('discount');
            $table->decimal('rate', 5, 2)->default(0);
            $table->string('commercial_reg_no', 100)->nullable();
            $table->timestamps();
        });

        // The agent_id columns on customers/invoices reference the user that is
        // the agent. SQLite cannot add foreign keys to existing tables, so we
        // only wire the constraints on real database engines (production MySQL).
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            });

            Schema::table('product_invoices', function (Blueprint $table) {
                $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            });

            Schema::table('service_invoices', function (Blueprint $table) {
                $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign(['agent_id']);
            });

            Schema::table('product_invoices', function (Blueprint $table) {
                $table->dropForeign(['agent_id']);
            });

            Schema::table('service_invoices', function (Blueprint $table) {
                $table->dropForeign(['agent_id']);
            });
        }

        Schema::dropIfExists('agent_profiles');
    }
};
