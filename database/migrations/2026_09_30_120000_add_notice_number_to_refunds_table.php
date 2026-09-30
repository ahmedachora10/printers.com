<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 150 — رقم «إشعار المرتجع» المطبوع: CN-{الفرع}-{التسلسل} بنمط أرقام
 * الفواتير. المرتجعات السابقة تُرقَّم هنا بترتيب إنشائها داخل كل فرع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('notice_number', 30)->nullable()->unique()->after('id');
        });

        $seq = [];
        DB::table('refunds')->orderBy('id')->select(['id', 'branch_id'])->each(function ($row) use (&$seq) {
            $seq[$row->branch_id] = ($seq[$row->branch_id] ?? 0) + 1;
            DB::table('refunds')->where('id', $row->id)
                ->update(['notice_number' => sprintf('CN-%03d-%05d', $row->branch_id, $seq[$row->branch_id])]);
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['notice_number']);
            $table->dropColumn('notice_number');
        });
    }
};
