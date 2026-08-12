<?php

namespace App\Actions\Customer;

use App\Enums\CustomerTierEnum;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * تعديل مستوى ولاء العميل يدوياً — المنفذ الوحيد للتنزيل، إذ يرقّي المحرّك
 * التلقائي ولا ينزّل أبداً.
 *
 * والتنزيل وحده لا يثبت: المستوى يُشتقّ من الإنفاق التراكمي عند كل فاتورة
 * مسدَّدة، فلو بقي الإنفاق فوق عتبة المستوى القديم أعاده أوّلُ اكتسابٍ إلى ما
 * كان. لذلك يقبل هذا الإجراء تصحيحاً اختيارياً للإنفاق التراكمي يُمرَّر معه،
 * وهو ما يجعل التنزيل نهائياً.
 *
 * السبب إلزامي ويُحفظ في سجلّ النشاط، فلكل تعديلٍ يدويٍّ على مالٍ يستحقه العميل
 * أثرٌ يُراجَع.
 */
class OverrideCustomerTierAction
{
    /** @param array{tier: string, cumulative_spend?: float|string|null, reason: string} $data */
    public function handle(Customer $customer, array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actor) {
            $previousTier = $customer->tier;
            $previousSpend = (float) $customer->cumulative_spend;

            $attributes = ['tier' => CustomerTierEnum::from($data['tier'])];

            if (($data['cumulative_spend'] ?? null) !== null && $data['cumulative_spend'] !== '') {
                $attributes['cumulative_spend'] = round((float) $data['cumulative_spend'], 2);
            }

            $customer->update($attributes);

            activity('customers')
                ->performedOn($customer)
                ->causedBy($actor)
                ->withProperties([
                    'from_tier' => $previousTier->value,
                    'to_tier' => $customer->tier->value,
                    'from_cumulative_spend' => $previousSpend,
                    'to_cumulative_spend' => (float) $customer->cumulative_spend,
                    'reason' => $data['reason'],
                ])
                ->log('تعديل يدوي لمستوى الولاء');

            return $customer->refresh();
        });
    }
}
