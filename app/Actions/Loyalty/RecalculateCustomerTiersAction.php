<?php

namespace App\Actions\Loyalty;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * إعادة بناء الإنفاق التراكمي وفئة الولاء لكل عميل من فواتيره — تصحيحٌ لمرّة
 * واحدة يُشغَّل بعد تغيير قاعدة الفئات.
 *
 * سببه أمران اجتمعا فأظهرا عملاء في فئةٍ لا يبلغها إنفاقهم:
 *
 *  1. الإنفاق كان يُضاف صافياً من الضريبة ويُخصم عند إرجاع الفاتورة شاملاً لها،
 *     فكل مرتجعٍ يقتطع 15% زيادة؛
 *  2. الفئة كانت لا تنزل أبداً، فتبقى معلّقة فوق إنفاقٍ هبط دونها.
 *
 * وقد صار المقياسان اليوم واحداً — الإجمالي شامل الضريبة — والفئة تتبع الإنفاق
 * صعوداً وهبوطاً، فهذا الإجراء يعيد كتابة التاريخ على القاعدة الجديدة.
 *
 * ما يُحتسب هو ما كان المحرّك ليحتسبه لو عمل بالقاعدة الجديدة من البداية:
 * الفواتير **المدفوعة** وحدها (فالمرتجعة والملغاة تخرج بحالتها)، بلا مندوب على
 * الفاتورة، لعميلٍ **فرد** غير مرتبط بمندوب، في فرعٍ برنامجُ ولائه مفعَّل —
 * ناقصاً ما استُرجع من قيمتها.
 *
 * النقاط لا تُمَسّ: رصيدها دفترٌ ثابت لا يُعاد بناؤه، وهذا الإجراء للفئات وحدها.
 */
class RecalculateCustomerTiersAction
{
    /**
     * @param  int|null  $branchId  فرعٌ بعينه، أو كل الفروع حين يكون null
     * @param  bool  $dryRun  يحسب ويُبلّغ بلا كتابة
     * @return array{scanned: int, changed: int, promoted: int, demoted: int, spendCorrected: int, rows: Collection<int, array<string, mixed>>}
     */
    public function handle(?int $branchId = null, bool $dryRun = false, ?User $actor = null): array
    {
        $configs = LoyaltyConfig::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        $rows = collect();
        $scanned = 0;

        foreach ($configs as $config) {
            $scanned += $this->recalculateBranch($config, $rows, $dryRun, $actor);
        }

        return [
            'scanned' => $scanned,
            'changed' => $rows->count(),
            'promoted' => $rows->where('direction', 'promoted')->count(),
            'demoted' => $rows->where('direction', 'demoted')->count(),
            'spendCorrected' => $rows->where('spendChanged', true)->count(),
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function recalculateBranch(LoyaltyConfig $config, Collection $rows, bool $dryRun, ?User $actor): int
    {
        $branchId = (int) $config->branch_id;

        // مجاميع الفواتير والمرتجعات تُقرأ للفرع كلّه في أربعة استعلامات، لا
        // استعلامَين لكل عميل.
        $spendByCustomer = $this->spendByCustomer($branchId);

        $customers = Customer::query()
            ->where('branch_id', $branchId)
            ->where('customer_type', CustomerTypeEnum::Individual)
            ->whereNull('agent_id')
            ->get();

        foreach ($customers as $customer) {
            $newSpend = round((float) ($spendByCustomer[$customer->id] ?? 0), 2);
            $newTier = $config->tierForSpend($newSpend);

            $oldSpend = round((float) $customer->cumulative_spend, 2);
            $oldTier = $customer->tier;

            if ($newTier === $oldTier && abs($newSpend - $oldSpend) < 0.01) {
                continue;
            }

            $rows->push([
                'customerId' => $customer->id,
                'customerName' => $customer->full_name,
                'branchId' => $branchId,
                'fromSpend' => $oldSpend,
                'toSpend' => $newSpend,
                'spendChanged' => abs($newSpend - $oldSpend) >= 0.01,
                'fromTier' => $oldTier,
                'toTier' => $newTier,
                'direction' => $this->direction($oldTier, $newTier),
            ]);

            if (! $dryRun) {
                $this->apply($customer, $newSpend, $newTier, $actor);
            }
        }

        return $customers->count();
    }

    private function apply(Customer $customer, float $spend, CustomerTierEnum $tier, ?User $actor): void
    {
        DB::transaction(function () use ($customer, $spend, $tier, $actor) {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $fromTier = $locked->tier;
            $fromSpend = (float) $locked->cumulative_spend;

            $locked->update(['cumulative_spend' => $spend, 'tier' => $tier]);

            // كل تغيير فئةٍ أثرٌ يُراجَع، تماماً كالتعديل اليدوي — وبلا مسبِّبٍ
            // حين يُشغَّل الأمر من المُجدوِل.
            activity('customers')
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'from_tier' => $fromTier->value,
                    'to_tier' => $tier->value,
                    'from_cumulative_spend' => round($fromSpend, 2),
                    'to_cumulative_spend' => $spend,
                    'reason' => 'إعادة احتساب الفئات بعد تصحيح قاعدة حد الإنفاق',
                ])
                ->log('إعادة احتساب مستوى الولاء');
        });
    }

    private function direction(CustomerTierEnum $from, CustomerTierEnum $to): string
    {
        return match (true) {
            $to->rank() > $from->rank() => 'promoted',
            $to->rank() < $from->rank() => 'demoted',
            default => 'unchanged',
        };
    }

    /**
     * الإنفاق المستحقّ لكل عميل في الفرع: إجمالي فواتيره المدفوعة بلا مندوب،
     * شاملةً الضريبة، ناقصاً ما استُرجع منها.
     *
     * @return Collection<int, float>
     */
    private function spendByCustomer(int $branchId): Collection
    {
        /** @var Collection<int, float> $spend */
        $spend = collect();

        $accumulate = function (Collection $sums, float $sign) use ($spend): void {
            foreach ($sums as $customerId => $total) {
                $spend[$customerId] = ($spend[$customerId] ?? 0.0) + $sign * (float) $total;
            }
        };

        $accumulate($this->invoiceTotals($branchId, ProductInvoice::class), 1);
        $accumulate($this->invoiceTotals($branchId, ServiceInvoice::class), 1);
        $accumulate($this->refundTotals($branchId, ProductInvoice::class, 'product_invoices'), -1);
        $accumulate($this->refundTotals($branchId, ServiceInvoice::class, 'service_invoices'), -1);

        // مرتجعٌ يتجاوز ما احتُسب لا يجعل الإنفاق سالباً.
        return $spend->map(fn (float $value) => max(0.0, $value));
    }

    /**
     * إجمالي الفواتير المدفوعة لكل عميل، شاملةً الضريبة.
     *
     * استبعاد فواتير المناديب يختلف بين النوعين: فاتورة المنتجات تحمل مندوبها في
     * عمود، وفاتورة الخدمات على جدولٍ وسيط قد يحمل عدّة مناديب.
     *
     * @param  class-string<ProductInvoice|ServiceInvoice>  $invoiceClass
     * @return Collection<int, float>
     */
    private function invoiceTotals(int $branchId, string $invoiceClass): Collection
    {
        return $invoiceClass::query()
            ->where('branch_id', $branchId)
            ->where('status', InvoiceStatusEnum::PAID)
            ->whereNotNull('customer_id')
            ->when(
                $invoiceClass === ServiceInvoice::class,
                fn ($q) => $q->whereDoesntHave('agents'),
                fn ($q) => $q->whereNull('agent_id'),
            )
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(total_amount) as total')
            ->pluck('total', 'customer_id');
    }

    /**
     * ما استُرجع من فواتير الفرع المدفوعة، منسوباً إلى عملائها. المرتجع لا يغيّر
     * حالة الفاتورة، فلا يُسقطها فلتر «المدفوعة» ولا بدّ من خصمه صراحةً — وبالشرط
     * نفسه الذي جمعت به الفواتير، وإلا خُصم مرتجعُ فاتورةٍ لم تُحتسب أصلاً.
     *
     * @param  class-string<ProductInvoice|ServiceInvoice>  $invoiceClass
     * @return Collection<int, float>
     */
    private function refundTotals(int $branchId, string $invoiceClass, string $table): Collection
    {
        $eligible = $this->invoiceTotals($branchId, $invoiceClass)->keys();

        if ($eligible->isEmpty()) {
            return collect();
        }

        return Refund::query()
            ->join($table, "{$table}.id", '=', 'refunds.invoice_id')
            ->where('refunds.invoice_type', (new $invoiceClass)->getMorphClass())
            ->where("{$table}.branch_id", $branchId)
            ->where("{$table}.status", InvoiceStatusEnum::PAID->value)
            ->whereNull("{$table}.deleted_at")
            ->whereIn("{$table}.customer_id", $eligible)
            ->when(
                $invoiceClass === ServiceInvoice::class,
                fn ($q) => $q->whereNotExists(fn ($sub) => $sub
                    ->selectRaw(1)
                    ->from('service_invoice_agent')
                    ->whereColumn('service_invoice_agent.service_invoice_id', "{$table}.id")),
                fn ($q) => $q->whereNull("{$table}.agent_id"),
            )
            ->groupBy("{$table}.customer_id")
            ->selectRaw("{$table}.customer_id as customer_id, SUM(refunds.amount) as total")
            ->pluck('total', 'customer_id');
    }
}
