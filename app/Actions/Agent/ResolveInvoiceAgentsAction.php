<?php

namespace App\Actions\Agent;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Models\Agent;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the several agents attached to a service invoice, validating each
 * belongs to the branch and is active, and returning a snapshot of the terms to
 * apply per agent. Duplicate ids are collapsed. Returns an empty list when no
 * agents are supplied.
 */
class ResolveInvoiceAgentsAction
{
    /**
     * @param  array<int, int|string>|null  $agentIds
     * @return list<array{agentId: int, mode: AgentDiscountModeEnum, type: AgentDiscountTypeEnum, rate: float}>
     */
    public function handle(?array $agentIds, int $branchId): array
    {
        $ids = collect($agentIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $agents = Agent::query()
            ->forBranch($branchId)
            ->withBranchTerms($branchId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $ids->map(function (int $id) use ($agents, $branchId) {
            $agent = $agents->get($id);
            // Terms come from the branch link — the same مندوب may carry a
            // different rate in each branch they work with.
            $terms = $agent?->termsForBranch($branchId);

            if (! $terms) {
                throw ValidationException::withMessages([
                    'agent_ids' => 'أحد المناديب المحددين غير صالح لهذا الفرع.',
                ]);
            }

            return [
                'agentId' => $agent->id,
                'mode' => $terms->discount_mode,
                'type' => $terms->discount_type ?? AgentDiscountTypeEnum::Percentage,
                'rate' => (float) $terms->rate,
            ];
        })->all();
    }
}
