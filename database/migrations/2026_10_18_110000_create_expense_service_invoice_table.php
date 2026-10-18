<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 187 — المصروف يُربط بأكثر من فاتورة، ولكل فاتورة حصّتها من المبلغ.
     * الربط القديم (`expenses.service_invoice_id`، تاسك 112) يُنقل صفّاً بكامل
     * المبلغ ثم يُحذف العمود — مصدرٌ واحد للربط.
     *
     * الترتيب مقصود: SQLite يعيد بناء `expenses` عند حذف العمود، وداخل المعاملة
     * يُطلق ذلك حذف الصفوف المتتالي (cascade) من جدولٍ يشير إليه — فالجدول الوسيط
     * يُنشأ بعد إعادة البناء، والروابط تُحمل في الذاكرة بينهما.
     */
    public function up(): void
    {
        $links = DB::table('expenses')->whereNotNull('service_invoice_id')->get(['id', 'service_invoice_id', 'total']);

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_invoice_id');
        });

        Schema::create('expense_service_invoice', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unique(['expense_id', 'service_invoice_id']);
        });

        $links->chunk(500)->each(fn ($chunk) => DB::table('expense_service_invoice')->insert($chunk->map(fn ($row) => [
            'expense_id' => $row->id,
            'service_invoice_id' => $row->service_invoice_id,
            'amount' => $row->total,
        ])->all()));
    }

    public function down(): void
    {
        // مصروفٌ بعدّة فواتير يعود إلى أولاها.
        $links = DB::table('expense_service_invoice')
            ->selectRaw('expense_id, MIN(service_invoice_id) as invoice_id')
            ->groupBy('expense_id')
            ->get();

        Schema::dropIfExists('expense_service_invoice');

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('service_invoice_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });

        $links->each(fn ($row) => DB::table('expenses')->where('id', $row->expense_id)->update(['service_invoice_id' => $row->invoice_id]));
    }
};
