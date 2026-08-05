<?php

use App\Enums\InvoiceStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `returned` invoice status (تاسك 4 — «حذف الفاتورة» صارت «استرجاع»).
 *
 * Fresh databases already get the value: both create-table migrations declare
 * the column as enum(InvoiceStatusEnum::all()), so adding the case is enough.
 * This migration only widens databases that were built before the new case.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['service_invoices', 'product_invoices'];

    public function up(): void
    {
        $this->applyStatuses(InvoiceStatusEnum::all());
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::table($table)
                ->where('status', InvoiceStatusEnum::RETURNED->value)
                ->update(['status' => InvoiceStatusEnum::CANCELLED->value]);
        }

        $this->applyStatuses(array_values(array_filter(
            InvoiceStatusEnum::all(),
            fn (string $status) => $status !== InvoiceStatusEnum::RETURNED->value,
        )));
    }

    /**
     * Rewrite the status column so it accepts exactly the given values. MySQL
     * takes a native ENUM redefinition; SQLite stores enums as a varchar with a
     * CHECK constraint, so the column is rebuilt through Laravel's change().
     *
     * @param  list<string>  $statuses
     */
    private function applyStatuses(array $statuses): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach ($this->tables as $table) {
            if ($driver === 'sqlite') {
                Schema::table($table, function (Blueprint $blueprint) use ($statuses) {
                    $blueprint->enum('status', $statuses)->change();
                });

                continue;
            }

            $values = implode(', ', array_map(fn (string $s) => "'{$s}'", $statuses));
            DB::statement("ALTER TABLE `{$table}` MODIFY `status` ENUM({$values}) NOT NULL");
        }
    }
};
