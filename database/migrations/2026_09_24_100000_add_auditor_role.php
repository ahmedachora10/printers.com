<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 125 — دور «مراجع الحسابات». `app:deploy` يشغّل migrate لا seed، فالصف
 * يُكتب هنا، والسيدر يكرّره بـ firstOrCreate لقواعد البيانات الجديدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->insertOrIgnore([
            'name' => 'auditor',
            'display_name' => 'مراجع الحسابات',
            'description' => 'الاطلاع على مبيعات الفرع وتقاريره دون أي تعديل',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'auditor')->delete();
    }
};
