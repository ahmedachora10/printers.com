<?php

namespace App\Actions\Agent;

use App\Enums\AgentDiscountTypeEnum;
use App\Models\Agent;
use Illuminate\Support\Collection;

/**
 * The active agents a branch may put on an invoice, with the terms that branch
 * negotiated with each — what the POS previews before the server recalculates.
 *
 * Shared by both counters (service and product), which asked the same question
 * with two identical private copies before agents became multi-branch.
 */
class ListBranchAgentsAction
{
    /** @return Collection<int, array<string, mixed>> */
    public function handle(?int $branchId): Collection
    {
        if (! $branchId) {
            return collect();
        }

        return Agent::query()
            ->forBranch($branchId)
            ->withBranchTerms($branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Agent $agent) use ($branchId) {
                $terms = $agent->termsForBranch($branchId);

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'discountMode' => $terms?->discount_mode?->value,
                    'discountType' => $terms?->discount_type?->value ?? AgentDiscountTypeEnum::Percentage->value,
                    'rate' => (float) ($terms?->rate ?? 0),
                    // تاسك 69: the POS preview has to reach the same figure the
                    // server will, or the cashier sees 8.70 and 7.70 is saved.
                    'deductMaterials' => (bool) ($terms?->deduct_materials ?? false),
                ];
            })
            ->values();
    }
}
