<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 122 — سجلُّ مطابقة الحسابات ليومٍ وفرع، يحمل ملف موازنة الشبكة.
 *
 * الهيكل وحده: أرقام المطابقة (صافي النظام، الأجهزة، الفرق، الاعتماد) تُضاف
 * مع التاسك 121 على هذا الجدول نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->date('date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reconciliations');
    }
};
