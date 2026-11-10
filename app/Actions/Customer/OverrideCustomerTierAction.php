<?php

namespace App\Actions\Customer;

use App\Enums\CustomerTierEnum;
use App\Models\CustomerBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * تعديل مستوى ولاء العميل يدوياً — في فرعٍ واحد، فالمستوى لكل فرع.
 *
 * والتعديل وحده لا يثبت: المستوى يُشتقّ من الإنفاق التراكمي عند كل فاتورة
 * مسدَّدة — صعوداً وهبوطاً معاً — فأيُّ مستوى يخالف ما يبلغه إنفاقُ العميل
 * يُنقَض عند أول اكتساب. لذلك يقبل هذا الإجراء تصحيحاً اختيارياً للإنفاق
 * التراكمي يُمرَّر معه، وهو ما يجعل التعديل نهائياً.
 *
 * السبب إلزامي ويُحفظ في سجلّ النشاط، فلكل تعديلٍ يدويٍّ على مالٍ يستحقه العميل
 * أثرٌ يُراجَع.
 */
class OverrideCustomerTierAction
{
    /** @param array{tier: string, cumulative_spend?: float|string|null, reason: string} $data */
    public function handle(CustomerBranch $account, array $data, User $actor): CustomerBranch
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            $previousTier = $account->tier;
            $previousSpend = (float) $account->cumulative_spend;

            $attributes = ['tier' => CustomerTierEnum::from($data['tier'])];

            if (($data['cumulative_spend'] ?? null) !== null && $data['cumulative_spend'] !== '') {
                $attributes['cumulative_spend'] = round((float) $data['cumulative_spend'], 2);
            }

            $account->update($attributes);

            activity('customers')
                ->performedOn($account->customer)
                ->causedBy($actor)
                ->withProperties([
                    'branch_id' => $account->branch_id,
                    'from_tier' => $previousTier->value,
                    'to_tier' => $account->tier->value,
                    'from_cumulative_spend' => $previousSpend,
                    'to_cumulative_spend' => (float) $account->cumulative_spend,
                    'reason' => $data['reason'],
                ])
                ->log('تعديل يدوي لمستوى الولاء');

            return $account->refresh();
        });
    }
}
