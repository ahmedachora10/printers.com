<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 121 — أرقام مطابقة الحسابات على هيكل التاسك 122.
 *
 * أرقام النظام (system_net و auto_total) nullable: تُحسب حيّةً حتى الاعتماد،
 * ثم تُجمَّد على الصف فلا تغيّر فاتورةٌ تُصحَّح غداً نتيجةَ يومٍ أُقفل.
 * الحالة (مطابق/عجز/زيادة) مشتقّةٌ من الفرق فلا تُخزَّن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_network')->default(false)->after('is_cash');
        });

        Schema::table('account_reconciliations', function (Blueprint $table) {
            $table->decimal('system_net', 12, 2)->nullable()->after('date');
            $table->decimal('auto_total', 12, 2)->nullable()->after('system_net');
            // سطور «التلقائي» كما كانت ساعة الاعتماد: [{name, total}].
            $table->json('auto_breakdown')->nullable()->after('auto_total');
            $table->decimal('devices_total', 12, 2)->default(0)->after('auto_total');
            $table->text('notes')->nullable()->after('devices_total');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::create('account_reconciliation_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained();
            $table->string('device_label', 100);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reconciliation_devices');

        Schema::table('account_reconciliations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['system_net', 'auto_total', 'auto_breakdown', 'devices_total', 'notes', 'approved_at']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_network');
        });
    }
};
