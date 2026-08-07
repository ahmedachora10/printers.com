<?php

namespace App\Actions\Agent;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UpdateAgentAction
{
    use Concerns\SyncsAgentBranches;

    /** @param array<string, mixed> $data */
    public function handle(User $agent, array $data): User
    {
        $actor = auth()->user();

        // Only a super-admin moves an agent's primary branch. A branch-scoped
        // actor may edit an agent they share a branch with, but must not drag
        // that agent's primary branch over to their own.
        if (! $actor->roleName->isSuperAdmin()) {
            unset($data['branch_id']);
        }

        $profile = Arr::only($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'commercial_reg_no']);
        $userData = Arr::except($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'commercial_reg_no', 'branches']);

        // Leave the password untouched when not provided.
        if (array_key_exists('password', $userData) && empty($userData['password'])) {
            unset($userData['password']);
        }

        return DB::transaction(function () use ($agent, $actor, $userData, $profile, $data) {
            $agent->update($userData);
            $agent->agentProfile()->updateOrCreate(['user_id' => $agent->id], $profile);

            $this->syncAgentBranches($agent, $data['branches'] ?? [], $actor);

            Cache::forget('user_role_'.$agent->id);

            return $agent->refresh();
        });
    }
}
