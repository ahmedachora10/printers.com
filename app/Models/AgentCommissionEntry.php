<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تاسك 217 — قيدٌ في دفتر المندوب: نصيبه من خطوة تحصيلٍ على فاتورة، بتاريخها.
 * لا يُعدَّل بعد كتابته إلا ختمُ الصرف (agent_payment_id)؛ التصحيح قيدٌ جديد
 * (SyncAgentCommissionAction).
 *
 * المستحق للمندوب = rebate_amount + line_commission_amount. المبيعات والخصم
 * للتقرير والبوابة وحدهما.
 */
class AgentCommissionEntry extends Model
{
    /** أعمدة المبالغ بالترتيب الذي تكتبه المزامنة. */
    public const AMOUNTS = ['sales_amount', 'discount_amount', 'rebate_amount', 'line_commission_amount'];

    /** ما يُصرف منها — ولا يُستردّ ما صُرف (M14). */
    public const PAYABLE = ['rebate_amount', 'line_commission_amount'];

    protected $fillable = [
        'agent_id',
        'branch_id',
        'invoice_type',
        'invoice_id',
        ...self::AMOUNTS,
        'earned_at',
        'agent_payment_id',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function invoice(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * صفٌّ لكل (مندوب، فاتورة) بمجاميع قيوده في $entries — يقرؤه تقرير عمولات
     * المناديب وبوابة المندوب. «مدفوعة» = كل مستحقّها مختوم.
     *
     * @param  Builder<self>  $entries  مُصفّاة بالتاريخ والفرع والمندوب
     * @return Collection<int, self>
     */
    public static function perInvoice(Builder $entries): Collection
    {
        return $entries
            ->groupBy('agent_id', 'invoice_type', 'invoice_id')
            ->select('agent_id', 'invoice_type', 'invoice_id')
            ->selectRaw('SUM(sales_amount) as sales, SUM(discount_amount) as discount')
            ->selectRaw('SUM(rebate_amount) as rebate, SUM(line_commission_amount) as line_commission')
            ->selectRaw('SUM(CASE WHEN agent_payment_id IS NULL THEN 0 ELSE rebate_amount + line_commission_amount END) as paid')
            ->selectRaw('SUM(CASE WHEN agent_payment_id IS NULL THEN rebate_amount + line_commission_amount ELSE 0 END) as unpaid')
            ->selectRaw('MAX(earned_at) as last_earned_at')
            ->with(['agent:id,name', 'invoice.user:id,name', 'invoice.branch:id,name', 'invoice.lines'])
            ->orderByDesc('last_earned_at')
            ->get();
    }

    /**
     * صفّ (مندوب، فاتورة) من perInvoice كما يعرضه التقرير والبوابة. التاريخ آخر
     * تحصيلٍ في النطاق.
     *
     * @return array<string, mixed>
     */
    public function invoiceRow(): array
    {
        $invoice = $this->invoice;
        $isService = $invoice instanceof ServiceInvoice;
        $lines = $invoice?->lines;
        $rest = ($lines?->count() ?? 0) - 1;
        $first = $lines?->first()?->{$isService ? 'service_name' : 'product_name'};

        return [
            'type' => $isService ? 'service' : 'product',
            'invoiceId' => (int) $this->invoice_id,
            'invoiceNumber' => $invoice?->invoice_number,
            'branchName' => $invoice?->branch?->name,
            'employeeName' => $invoice?->user?->name,
            // البند الأول، وعدد ما بعده.
            'itemsLabel' => $rest > 0 ? "{$first} و{$rest} أخرى" : $first,
            'invoiceTotal' => (float) ($invoice?->total_amount ?? 0),
            'discount' => round((float) $this->discount, 2),
            'rebate' => round((float) $this->rebate, 2),
            'lineCommission' => round((float) $this->line_commission, 2),
            'amount' => round((float) $this->rebate + (float) $this->line_commission, 2),
            'isPaid' => (float) $this->paid > 0 && round((float) $this->unpaid, 2) <= 0,
            'status' => $invoice?->status->value,
            'statusLabel' => $invoice?->status->label(),
            'date' => Carbon::parse($this->last_earned_at)->toDateString(),
        ];
    }
}
