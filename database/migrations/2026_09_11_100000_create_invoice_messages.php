<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 100 — المحادثة الداخلية على فاتورة الخدمات، بدل حقل التاسك 95 الذي
 * يُكتب فوقه. فواتير المنتجات تبقى على حقلها.
 *
 * - invoice_messages: رسالةٌ لكل صفّ، لا تُستبدل. من عدّلها أو حذفها ونصُّها
 *   القديم في activity_log لا في أعمدة هنا.
 * - invoice_thread_participants: من أرسل أو أُشير إليه أو فتح الخيط، ومعه موضع
 *   قراءته — منه يُشتقّ غير المقروء و«مقروءة»، وهو ما يُدخل الموظفَ المُشار إليه.
 * - الإغلاق عمودان على الفاتورة: جدول threads سيكون 1:1 لعمودين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained()->cascadeOnDelete();
            // NULL = ملاحظة التاسك 95 المُرحَّلة أدناه، بلا مؤلف معروف.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // فارغ حين تكون الرسالة مرفقاً وحده.
            $table->text('body')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_invoice_id', 'id']);
        });

        Schema::create('invoice_thread_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'service_invoice_id']);
        });

        Schema::table('service_invoices', function (Blueprint $table) {
            $table->timestamp('messages_closed_at')->nullable();
            $table->foreignId('messages_closed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // ما كُتب في الحقل القديم يصير أول رسالة في خيط فاتورته، بتاريخ آخر
        // تحديث لها. العمود نفسه يبقى حتى يُتأكَّد من الترحيل على الإنتاج.
        DB::table('service_invoices')
            ->whereNotNull('internal_notes')
            ->where('internal_notes', '!=', '')
            ->orderBy('id')
            ->select(['id', 'internal_notes', 'updated_at'])
            ->chunk(500, function ($invoices) {
                DB::table('invoice_messages')->insert($invoices->map(fn ($invoice) => [
                    'service_invoice_id' => $invoice->id,
                    'user_id' => null,
                    'body' => $invoice->internal_notes,
                    'created_at' => $invoice->updated_at,
                    'updated_at' => $invoice->updated_at,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('messages_closed_by');
            $table->dropColumn('messages_closed_at');
        });

        Schema::dropIfExists('invoice_thread_participants');
        Schema::dropIfExists('invoice_messages');
    }
};
