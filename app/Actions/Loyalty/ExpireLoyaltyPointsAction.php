<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\CustomerBranch;
use App\Models\LoyaltyConfig;
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
 * ويُستثنى من الإنهاء ما كان محجوزاً على فاتورة لم تُعتمد بعد، فذاك خصمٌ وقع على
 * الفاتورة وينتظر الاعتماد ليقع على الرصيد.
 */
class ExpireLoyaltyPointsAction
{
    public function __construct(
        private readonly ResolveAvailablePointsAction $availablePoints,
    ) {}

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

        // الخمول لكل فرع: آخر حركة ولاء أو فاتورة للعميل في هذا الفرع.
        $accounts = CustomerBranch::query()
            ->where('branch_id', $config->branch_id)
            ->where('points_balance', '>', 0)
            ->select('customer_branch.*');

        foreach (['loyalty_transactions' => 'last_loyalty_at', 'product_invoices' => 'last_product_invoice_at', 'service_invoices' => 'last_service_invoice_at'] as $table => $alias) {
            $accounts->selectSub(DB::table($table)->selectRaw('max(created_at)')
                ->whereColumn("{$table}.customer_id", 'customer_branch.customer_id')
                ->whereColumn("{$table}.branch_id", 'customer_branch.branch_id'), $alias);
        }

        $expired = 0;

        foreach ($accounts->get() as $account) {
            if ($this->lastActivityAt($account)->greaterThan($cutoff)) {
                continue;
            }

            if ($this->expire($account)) {
                $expired++;
            }
        }

        return $expired;
    }

    /** آخر ما يدلّ على حياة الحساب، وأقدمُ ما يمكن أن يكون هو تاريخ ربطه بالفرع. */
    private function lastActivityAt(CustomerBranch $account): CarbonInterface
    {
        $candidates = array_filter([
            $account->last_loyalty_at,
            $account->last_product_invoice_at,
            $account->last_service_invoice_at,
            $account->created_at,
        ]);

        $timestamps = array_map(fn ($value) => Date::parse($value), $candidates);

        return collect($timestamps)->max() ?? Date::now();
    }

    /** @return bool هل انتهت نقاطٌ فعلاً؟ */
    private function expire(CustomerBranch $account): bool
    {
        return DB::transaction(function () use ($account) {
            $locked = CustomerBranch::lockFor($account->customer_id, $account->branch_id);
            $balance = (int) $locked?->points_balance;

            // قد يكون العميل قد اشترى بين القراءة والقفل، فيسقط سبب الإنهاء.
            if ($balance <= 0) {
                return false;
            }

            // النقاط المحجوزة على فاتورة تنتظر الاعتماد لا تنتهي: خصمُها مستحقٌّ
            // على الفاتورة وقد نزل من إجماليها فعلاً، فإنهاؤها يمنع اعتمادها.
            $reserved = $this->availablePoints->reserved($locked->customer_id, $locked->branch_id);
            $expirable = $balance - $reserved;

            if ($expirable <= 0) {
                return false;
            }

            $locked->update(['points_balance' => $reserved]);

            $locked->record([
                'type' => LoyaltyTransactionTypeEnum::Expire,
                'points' => -$expirable,
                'notes' => 'انتهاء صلاحية النقاط لخمول الحساب',
            ]);

            return true;
        });
    }
}
