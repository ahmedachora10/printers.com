<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 146 — أجهزة الشبكة (لكل فرع، واحدٌ افتراضي) وأنواع البطاقات (قائمة عامة).
 *
 * صفّ موازنة المطابقة صار «جهاز + نوع بطاقة + مبلغ». الصفوف القديمة تبقى بـ
 * device_label نصّاً والعمودان الجديدان null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            // طريقة الدفع «شبكة» التي يُحصِّل بها الجهاز — تُنسخ إلى صفّ المطابقة.
            $table->foreignId('payment_method_id')->constrained();
            $table->string('name', 100);
            $table->string('number', 50);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('card_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('card_types')->insert(array_map(fn ($name, $i) => [
            'name' => $name,
            'sort_order' => $i + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $names = ['مدى', 'فيزا', 'ماستر كارد'], array_keys($names)));

        Schema::table('account_reconciliation_devices', function (Blueprint $table) {
            $table->foreignId('network_device_id')->nullable()->after('payment_method_id')->constrained();
            $table->foreignId('card_type_id')->nullable()->after('network_device_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('account_reconciliation_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_type_id');
            $table->dropConstrainedForeignId('network_device_id');
        });

        Schema::dropIfExists('card_types');
        Schema::dropIfExists('network_devices');
    }
};
