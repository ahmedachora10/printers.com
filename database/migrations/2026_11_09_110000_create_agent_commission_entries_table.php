<?php

use App\Actions\Agent\SyncAgentCommissionAction;
use App\Enums\InvoiceStatusEnum;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 217 — دفتر عمولة المندوب: صفٌّ لكل خطوة تحصيل (دفعة، اعتماد، مرتجع)
 * بتاريخها، كدفتر عمولة الموظف (تاسك 191). الصرف يختم الصفوف (agent_payment_id)
 * بدل ختم الفاتورة/صفّ المندوب عليها — عمودا الختم القديمان لم يعودا يُقرآن.
 *
 * المبيعات والخصم يُقيَّدان معه بنسبة المحصَّل، فيُؤرَّخ كل ما يخصّ المندوب بتاريخ
 * التحصيل كتقرير المبيعات (تاسكات 201–206).
 */
return new class extends Migration
{
    private const PAID = InvoiceStatusEnum::PAID->value;

    public function up(): void
    {
        Schema::create('agent_commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users');
            $table->foreignId('branch_id')->constrained();
            $table->morphs('invoice');
            $table->decimal('sales_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('rebate_amount', 12, 2)->default(0);
            $table->decimal('line_commission_amount', 12, 2)->default(0);
            $table->timestamp('earned_at');
            $table->foreignId('agent_payment_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['agent_id', 'earned_at']);
        });

        $now = now();

        // ما صُرف قبل الدفتر: صفٌّ واحد بالمبلغ كاملاً، بتاريخ السداد وختم دفعته.
        DB::table('agent_commission_entries')->insertUsing(
            ['agent_id', 'branch_id', 'invoice_type', 'invoice_id', 'sales_amount', 'discount_amount', 'rebate_amount', 'line_commission_amount', 'earned_at', 'agent_payment_id', 'created_at', 'updated_at'],
            DB::table('service_invoice_agent as a')
                ->join('service_invoices as i', 'i.id', '=', 'a.service_invoice_id')
                ->whereNotNull('a.agent_payment_id')
                ->where('i.status', self::PAID)
                ->selectRaw('a.agent_id, i.branch_id, ?, i.id, i.total_amount, a.discount_amount, a.rebate_amount, a.line_commission_amount, COALESCE(i.paid_at, i.created_at), a.agent_payment_id, ?, ?', [ServiceInvoice::class, $now, $now]),
        );

        DB::table('agent_commission_entries')->insertUsing(
            ['agent_id', 'branch_id', 'invoice_type', 'invoice_id', 'sales_amount', 'discount_amount', 'rebate_amount', 'line_commission_amount', 'earned_at', 'agent_payment_id', 'created_at', 'updated_at'],
            DB::table('product_invoices')
                ->whereNotNull('agent_id')
                ->whereNotNull('agent_payment_id')
                ->where('status', self::PAID)
                ->selectRaw('agent_id, branch_id, ?, id, total_amount, agent_discount, agent_rebate, 0, COALESCE(paid_at, created_at), agent_payment_id, ?, ?', [ProductInvoice::class, $now, $now]),
        );

        // وما لم يُصرف يُكتب بنسبة المحصَّل، كلُّ دفعةٍ بتاريخها — والمصروف أعلاه لا يتكرّر.
        $sync = app(SyncAgentCommissionAction::class);
        $collected = [self::PAID, InvoiceStatusEnum::PARTIALLY_PAID->value];

        ServiceInvoice::query()->whereIn('status', $collected)->whereHas('invoiceAgents')
            ->each(fn (ServiceInvoice $invoice) => $sync->handle($invoice, $invoice->paid_at ?? $now));
        ProductInvoice::query()->whereIn('status', $collected)->whereNotNull('agent_id')
            ->each(fn (ProductInvoice $invoice) => $sync->handle($invoice, $invoice->paid_at ?? $now));
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commission_entries');
    }
};
