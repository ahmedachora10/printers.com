<?php

namespace App\Actions\Agent;

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
     * @return list<array{agentId: int, mode: \App\Enums\AgentDiscountModeEnum, type: AgentDiscountTypeEnum, rate: float}>
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
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('agentProfile')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $ids->map(function (int $id) use ($agents) {
            $agent = $agents->get($id);

            if (! $agent || ! $agent->agentProfile) {
                throw ValidationException::withMessages([
                    'agent_ids' => 'أحد الوكلاء المحددين غير صالح لهذا الفرع.',
                ]);
            }

            return [
                'agentId' => $agent->id,
                'mode' => $agent->agentProfile->discount_mode,
                'type' => $agent->agentProfile->discount_type ?? AgentDiscountTypeEnum::Percentage,
                'rate' => (float) $agent->agentProfile->rate,
            ];
        })->all();
    }
}
