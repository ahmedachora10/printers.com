<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * انتهاء صلاحية النقاط بخمول العميل: إن مرّت مدةُ الفرع المحدَّدة (expiry_months)
 * بلا أي حركة للعميل صُفِّر رصيدُه كاملاً بصفٍّ واحد من نوع «انتهاء صلاحية».
 *
 * الخمول يُقاس بآخر ما يدلّ على حياة الحساب: آخر حركة ولاء (اكتساب أو استبدال أو
 * تعديل)، أو آخر فاتورة — خدماتٍ كانت أو منتجات، بأي حالة، فالفاتورة الآجلة شراءٌ
 * وإن لم تُسدَّد بعد — وإلا فتاريخ إنشاء العميل نفسه. وأي شراء جديد يُصفّر العدّاد
 * ويُنقذ الرصيد كلّه، فالمدة تُقرأ للعميل لا لكل دفعة نقاط.
 *
 * الفروع التي لا مدة لها (NULL) — وهو الوضع الافتراضي — لا تنتهي نقاطها أبداً.
 */
class ExpireLoyaltyPointsAction
{
    /**
     * @param  int|null  $branchId  فرعٌ بعينه، أو كل الفروع حين يكون null
     * @param  CarbonInterface|null  $asOf  لحظة القياس، وهي الآن افتراضاً
     * @return int عدد العملاء الذين صُفِّرت أرصدتهم
     */
    public function handle(?int $branchId = null, ?CarbonInterface $asOf = null): int
    {
        $asOf ??= Date::now();

        $configs = LoyaltyConfig::query()
            ->where('is_active', true)
            ->whereNotNull('expiry_months')
            ->where('expiry_months', '>', 0)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        $expired = 0;

        foreach ($configs as $config) {
            $expired += $this->expireForBranch($config, $asOf);
        }

        return $expired;
    }

    private function expireForBranch(LoyaltyConfig $config, CarbonInterface $asOf): int
    {
        $cutoff = $asOf->copy()->subMonths((int) $config->expiry_months);

        $customers = Customer::query()
            ->where('branch_id', $config->branch_id)
            ->where('points_balance', '>', 0)
            ->withMax('loyaltyTransactions as last_loyalty_at', 'created_at')
            ->withMax('productInvoices as last_product_invoice_at', 'created_at')
            ->withMax('serviceInvoices as last_service_invoice_at', 'created_at')
            ->get();

        $expired = 0;

        foreach ($customers as $customer) {
            if ($this->lastActivityAt($customer)->greaterThan($cutoff)) {
                continue;
            }

            $this->expire($customer);
            $expired++;
        }

        return $expired;
    }

    /** آخر ما يدلّ على حياة الحساب، وأقدمُ ما يمكن أن يكون هو تاريخ إنشائه. */
    private function lastActivityAt(Customer $customer): CarbonInterface
    {
        $candidates = array_filter([
            $customer->last_loyalty_at,
            $customer->last_product_invoice_at,
            $customer->last_service_invoice_at,
            $customer->created_at,
        ]);

        $timestamps = array_map(fn ($value) => Date::parse($value), $candidates);

        return collect($timestamps)->max() ?? Date::now();
    }

    private function expire(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $balance = (int) $locked->points_balance;

            // قد يكون العميل قد اشترى بين القراءة والقفل، فيسقط سبب الإنهاء.
            if ($balance <= 0) {
                return;
            }

            $locked->update(['points_balance' => 0]);

            LoyaltyTransaction::create([
                'customer_id' => $locked->id,
                'invoice_id' => null,
                'invoice_type' => null,
                'type' => LoyaltyTransactionTypeEnum::Expire,
                'points' => -$balance,
                'balance_after' => 0,
                'notes' => 'انتهاء صلاحية النقاط لخمول الحساب',
            ]);
        });
    }
}
