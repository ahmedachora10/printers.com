<?php

use App\Enums\InvoiceStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يضيف حالة `partially_paid` (مدفوعة جزئياً) للفاتورتين — الفاتورة التي قُبض عليها
 * عربون أو دفعة ولمّا يكتمل سدادها.
 *
 * قواعد البيانات الجديدة تحمل القيمة أصلاً: هجرتا الإنشاء تعرّفان العمود بـ
 * enum(InvoiceStatusEnum::all())، فتكفي إضافة الحالة إلى الـ enum. هذه الهجرة
 * توسّع القواعد التي أُنشئت قبل الحالة الجديدة.
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
        // ما قُبض بعضه ولم يكتمل يعود آجلاً — الدفعات نفسها تبقى في invoice_payments.
        foreach ($this->tables as $table) {
            DB::table($table)
                ->where('status', InvoiceStatusEnum::PARTIALLY_PAID->value)
                ->update(['status' => InvoiceStatusEnum::DUE->value]);
        }

        $this->applyStatuses(array_values(array_filter(
            InvoiceStatusEnum::all(),
            fn (string $status) => $status !== InvoiceStatusEnum::PARTIALLY_PAID->value,
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
