<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the single-agent link on a service invoice to a many-to-many pivot so an
 * invoice may carry several agents at once (shared rebate). Each pivot row keeps
 * a snapshot of the agent's terms and is settled independently via its own
 * agent_payment_id. The invoice keeps `agent_discount` as the summed invoice-level
 * discount (it reduces the taxable base); the per-agent `agent_rebate` and its
 * settlement move onto the pivot. Product invoices are unchanged (single agent).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('service_invoice_agent', function (Blueprint $table) use ($sqlite) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agent_id');
            // Snapshot of the agent's terms at invoice time — the agent profile
            // may change later, but a settled invoice must not.
            $table->string('discount_mode', 20);
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('rate', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('rebate_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('agent_payment_id')->nullable();
            $table->timestamps();

            $table->unique(['service_invoice_id', 'agent_id']);
            $table->index('agent_id');
            $table->index('agent_payment_id');

            if (! $sqlite) {
                $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('agent_payment_id')->references('id')->on('agent_payments')->nullOnDelete();
            }
        });

        // Backfill the pivot from every service invoice that already has an agent,
        // snapshotting the current profile terms and carrying over the recorded
        // rebate/discount and any settlement already made.
        DB::table('service_invoices')
            ->whereNotNull('agent_id')
            ->orderBy('id')
            ->select([
                'service_invoices.id',
                'service_invoices.agent_id',
                'service_invoices.agent_discount',
                'service_invoices.agent_rebate',
                'service_invoices.agent_payment_id',
                'service_invoices.created_at',
                'service_invoices.updated_at',
            ])
            ->chunkById(500, function ($rows) {
                $profiles = DB::table('agent_profiles')
                    ->whereIn('user_id', $rows->pluck('agent_id')->unique())
                    ->get()
                    ->keyBy('user_id');

                $inserts = $rows->map(function ($row) use ($profiles) {
                    $profile = $profiles->get($row->agent_id);

                    return [
                        'service_invoice_id' => $row->id,
                        'agent_id' => $row->agent_id,
                        'discount_mode' => $profile->discount_mode ?? 'discount',
                        'discount_type' => $profile->discount_type ?? 'percentage',
                        'rate' => $profile->rate ?? 0,
                        'discount_amount' => $row->agent_discount ?? 0,
                        'rebate_amount' => $row->agent_rebate ?? 0,
                        'agent_payment_id' => $row->agent_payment_id,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                })->all();

                DB::table('service_invoice_agent')->insert($inserts);
            });

        // Drop the now-migrated scalar columns from service_invoices. The FK on
        // agent_id (agents module) and agent_payment_id (payments module) must be
        // dropped first on real engines; SQLite carries no such constraints.
        Schema::table('service_invoices', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign(['agent_id']);
                $table->dropForeign(['agent_payment_id']);
            }
            // The agent_payment_id column carries an index; drop it before the
            // column so SQLite's table rebuild does not trip over a dangling index.
            $table->dropIndex('service_invoices_agent_payment_id_index');
            $table->dropColumn(['agent_id', 'agent_rebate', 'agent_payment_id']);
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('service_invoices', function (Blueprint $table) use ($sqlite) {
            $table->foreignId('agent_id')->nullable()->after('customer_id');
            $table->decimal('agent_rebate', 12, 2)->default(0)->after('agent_discount');
            $table->unsignedBigInteger('agent_payment_id')->nullable()->index()->after('agent_rebate');

            if (! $sqlite) {
                $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('agent_payment_id')->references('id')->on('agent_payments')->nullOnDelete();
            }
        });

        // Fold each invoice's first pivot row back onto the scalar columns.
        $pivots = DB::table('service_invoice_agent')->orderBy('id')->get()->groupBy('service_invoice_id');

        foreach ($pivots as $invoiceId => $rows) {
            $first = $rows->first();
            DB::table('service_invoices')->where('id', $invoiceId)->update([
                'agent_id' => $first->agent_id,
                'agent_rebate' => $rows->sum('rebate_amount'),
                'agent_payment_id' => $first->agent_payment_id,
            ]);
        }

        Schema::dropIfExists('service_invoice_agent');
    }
};
