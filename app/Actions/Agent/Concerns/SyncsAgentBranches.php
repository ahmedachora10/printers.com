<?php

namespace App\Actions\Agent\Concerns;

use App\Models\User;

/**
 * Writes the agent↔branch links that decide where an agent (مندوب) may be
 * invoiced and on what terms.
 */
trait SyncsAgentBranches
{
    /**
     * A super-admin sees every link, so their payload is authoritative: links
     * they left out are removed. A branch-scoped actor only ever sees and posts
     * their own branch, so theirs must never detach the links other branches
     * negotiated — it upserts its own row and leaves the rest alone.
     *
     * @param  array<int, array<string, mixed>>  $branches
     */
    protected function syncAgentBranches(User $agent, array $branches, User $actor): void
    {
        $terms = collect($branches)
            ->filter(fn (array $row) => ! empty($row['branch_id']))
            ->mapWithKeys(fn (array $row) => [
                (int) $row['branch_id'] => [
                    'discount_mode' => $row['discount_mode'],
                    'discount_type' => $row['discount_type'] ?? 'percentage',
                    'rate' => $row['rate'] ?? 0,
                ],
            ])
            ->all();

        if ($terms === []) {
            return;
        }

        if ($actor->roleName->isSuperAdmin()) {
            $agent->agentBranches()->sync($terms);
        } else {
            $agent->agentBranches()->syncWithoutDetaching($terms);
        }

        $agent->unsetRelation('agentBranches');
    }
}
