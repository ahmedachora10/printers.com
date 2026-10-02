<?php

namespace App\Models;

use App\Enums\LineAgentCommissionTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * تاسك 153: عمولة المندوب المحدَّدة لخدمة فرع. الصفّ يفرض نوعها وقيمتها على
 * الموظف في نقطة البيع (`CalculateServiceInvoiceAction`)؛ ولا صفّ = يكتبها الموظف.
 */
class AgentService extends Model
{
    protected $fillable = [
        'agent_id',
        'branch_service_id',
        'commission_type',
        'commission_value',
    ];

    protected $casts = [
        'commission_type' => LineAgentCommissionTypeEnum::class,
        'commission_value' => 'decimal:2',
    ];

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * عمولات المناديب المحدَّدة لخدمات الصفحة، مجمّعةً بالخدمة لنافذة التعديل.
     *
     * @param  iterable<int>  $branchServiceIds
     * @return Collection<int|string, Collection<int, array{agentId: int, type: string, value: float}>>
     */
    public static function groupedByService(iterable $branchServiceIds): Collection
    {
        return self::query()
            ->whereIn('branch_service_id', $branchServiceIds)
            ->get()
            ->groupBy('branch_service_id')
            ->map(fn ($rows) => $rows->map(fn (self $r) => [
                'agentId' => $r->agent_id,
                'type' => $r->commission_type->value,
                'value' => (float) $r->commission_value,
            ])->values())
            ->toBase();
    }
}
