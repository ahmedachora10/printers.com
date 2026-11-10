<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عميلٌ واحد ينتمي لفرعٍ أو أكثر. الهوية (الاسم، الجوال، البريد، النوع، الشركة،
 * الرقم الضريبي) تبقى على customers، وما يخصّ كل فرع (النقاط، الإنفاق، المستوى،
 * حد الائتمان، المندوب، الملاحظات، النشاط) ينتقل إلى حسابه في customer_branch.
 *
 * البيانات أولاً: حسابٌ لكل صفٍّ حيّ من فرعه، ثم دمج صفوف الجوال الواحد (نسخ
 * الفروع التي صنعها PR #139) في عميلٍ واحد تتبعه فواتيره وحركاته وعناوينه، ثم
 * تُحذف الأعمدة المنقولة. لا رجوع: خذ نسخةً احتياطية قبل النشر.
 */
return new class extends Migration
{
    /** الأعمدة التي تنتقل من customers إلى حساب الفرع. */
    private const ACCOUNT_COLUMNS = ['points_balance', 'cumulative_spend', 'tier', 'credit_limit', 'agent_id', 'notes', 'is_active'];

    private const IDENTITY_COLUMNS = ['full_name', 'email', 'customer_type', 'company_name', 'tax_number'];

    public function up(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('customer_branch', function (Blueprint $table) use ($sqlite) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->integer('points_balance')->default(0);
            $table->decimal('cumulative_spend', 12, 2)->default(0);
            $table->string('tier', 20)->default('none');
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->unsignedBigInteger('agent_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'branch_id']);
            $table->index('branch_id');

            if (! $sqlite) {
                $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            }
        });

        foreach (['loyalty_transactions', 'customer_addresses'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete());
        }

        DB::transaction(function () {
            $this->backfill();
            $this->mergeSamePhone();
        });

        Schema::table('customers', function (Blueprint $table) use ($sqlite) {
            $table->dropForeign(['branch_id']);

            if (! $sqlite) {
                $table->dropForeign(['agent_id']);
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_phone_branch_unique');
            $table->dropIndex('customers_branch_active_name_index');
            $table->dropIndex(['agent_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['branch_id', ...self::ACCOUNT_COLUMNS]);
            $table->unique('phone');
        });
    }

    /** حسابٌ لكل عميلٍ حيّ في فرعه؛ والمحذوف بلا حساب (مفصولٌ عن فرعه). */
    private function backfill(): void
    {
        DB::table('customers')->whereNull('deleted_at')->orderBy('id')
            ->chunkById(500, fn (Collection $rows) => DB::table('customer_branch')->insert($rows->map(fn ($row) => [
                'customer_id' => $row->id,
                'branch_id' => $row->branch_id,
                ...collect($row)->only(self::ACCOUNT_COLUMNS)->all(),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all()));

        foreach (['loyalty_transactions', 'customer_addresses'] as $table) {
            DB::table($table)->update([
                'branch_id' => DB::raw("(select branch_id from customers where customers.id = {$table}.customer_id)"),
            ]);
        }
    }

    /**
     * صفوف الجوال الواحد عميلٌ واحد. الباقي: الحيّ أولاً، ثم اسمٌ غير «عميل عابر»،
     * ثم الأحدث تعديلاً. صفوف الجوال الواحد في فروعٍ مختلفة (الفهرس القديم)، فلا
     * تتصادم حساباتها.
     */
    private function mergeSamePhone(): void
    {
        DB::table('customers')
            ->whereIn('phone', DB::table('customers')->select('phone')->groupBy('phone')->havingRaw('count(*) > 1'))
            ->get(['id', 'phone', 'deleted_at', 'updated_at', ...self::IDENTITY_COLUMNS])
            ->groupBy('phone')
            ->each(function (Collection $group) {
                $sorted = $group->sortByDesc(fn ($c) => [$c->deleted_at === null, $c->full_name !== 'عميل عابر', (string) $c->updated_at]);
                $keep = $sorted->first();
                $others = $sorted->slice(1)->pluck('id')->all();

                foreach (['product_invoices', 'service_invoices', 'quotations', 'loyalty_transactions', 'customer_addresses', 'customer_branch'] as $table) {
                    DB::table($table)->whereIn('customer_id', $others)->update(['customer_id' => $keep->id]);
                }

                DB::table('activity_log')->where('subject_type', 'App\\Models\\Customer')
                    ->whereIn('subject_id', $others)->update(['subject_id' => $keep->id]);

                // يُملأ الفارغ من هوية الباقي من صفوفه الأخرى، ولا يُكتب فوق المسجَّل.
                $filled = collect(self::IDENTITY_COLUMNS)
                    ->filter(fn ($column) => $keep->{$column} === null)
                    ->mapWithKeys(fn ($column) => [$column => $sorted->pluck($column)->filter()->first()])
                    ->filter();

                if ($filled->isNotEmpty()) {
                    DB::table('customers')->where('id', $keep->id)->update($filled->all());
                }

                DB::table('customers')->whereIn('id', $others)->delete();
            });
    }
};
