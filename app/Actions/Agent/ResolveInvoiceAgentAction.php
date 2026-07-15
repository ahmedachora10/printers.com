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
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('agentProfile')
            ->find($agentId);

        if (! $agent || ! $agent->agentProfile) {
            throw ValidationException::withMessages([
                'agent_id' => 'الوكيل المحدد غير صالح لهذا الفرع.',
            ]);
        }

        return [
            $agent->id,
            $agent->agentProfile->discount_mode,
            $agent->agentProfile->discount_type ?? AgentDiscountTypeEnum::Percentage,
            (float) $agent->agentProfile->rate,
        ];
    }
}
