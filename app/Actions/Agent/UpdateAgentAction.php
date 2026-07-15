<?php

namespace App\Actions\Agent;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UpdateAgentAction
{
    /** @param array<string, mixed> $data */
    public function handle(User $agent, array $data): User
    {
        $actor = auth()->user();

        // Branch-scoped actors keep their agents inside their own branch.
        if (! $actor->roleName->isSuperAdmin() && array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $actor->branchId;
        }

        $profile = Arr::only($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'commercial_reg_no']);
        $userData = Arr::except($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'commercial_reg_no']);

        // Leave the password untouched when not provided.
        if (array_key_exists('password', $userData) && empty($userData['password'])) {
            unset($userData['password']);
        }

        return DB::transaction(function () use ($agent, $userData, $profile) {
            $agent->update($userData);
            $agent->agentProfile()->updateOrCreate(['user_id' => $agent->id], $profile);

            Cache::forget('user_role_'.$agent->id);

            return $agent->refresh();
        });
    }
}
