<?php

namespace App\Actions\Agent;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeleteAgentAction
{
    public function handle(User $agent): void
    {
        DB::transaction(function () use ($agent) {
            // Soft-deletes the user; the agent_profile row is retained and the
            // hasOne FK uses nullOnDelete on real engines for invoice references.
            $agent->agentProfile()->delete();
            $agent->delete();

            Cache::forget('user_role_'.$agent->id);
        });
    }
}