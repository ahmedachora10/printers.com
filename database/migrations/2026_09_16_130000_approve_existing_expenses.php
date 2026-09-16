<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * التقارير صارت تحسب المعتمد وحده، فكل مصروفٍ سُجّل قبل الاعتماد يُعدّ
     * معتمداً عند تسجيله — وإلا سقطت مصروفات كل الأيام الماضية من التقارير.
     */
    public function up(): void
    {
        DB::table('expenses')->whereNull('approved_at')->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void {}
};
