<?php

namespace App\Actions\Agent;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Models\Agent;
use Illuminate\Validation\ValidationException;

class ResolveInvoiceAgentAction
{
    /**
     * Resolve the agent attached to an invoice and the terms to apply.
     *
     * @return array{0: ?int, 1: ?AgentDiscountModeEnum, 2: AgentDiscountTypeEnum, 3: float} [agentId, mode, type, rate]
     */
    public function handle(?int $agentId, int $branchId): array
    {
        if (! $agentId) {
            return [null, null, AgentDiscountTypeEnum::Percentage, 0.0];
        }

        $agent = Agent::query()
            ->forBranch($branchId)
            ->withBranchTerms($branchId)
            ->where('is_active', true)
            ->find($agentId);

        // The terms come from the branch link, never from the agent profile: the
        // same مندوب may work with several branches on different rates.
        $terms = $agent?->termsForBranch($branchId);

        if (! $terms) {
            throw ValidationException::withMessages([
                'agent_id' => 'المندوب المحدد غير صالح لهذا الفرع.',
            ]);
        }

        return [
            $agent->id,
            $terms->discount_mode,
            $terms->discount_type ?? AgentDiscountTypeEnum::Percentage,
            (float) $terms->rate,
        ];
    }
}
