<?php

namespace App\Actions\Agent;

use App\Enums\Roles;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAgentAction
{
    public function handle(User $agent): void
    {
        // A staff member who is also an agent keeps their account: only the agent
        // role goes, which drops them from every agent list. Profile and branch
        // terms stay so past invoices still read them.
        if (! $agent->roleName?->isAgent()) {
            $agent->removeRole(Roles::AGENT->value);

            return;
        }

        DB::transaction(function () use ($agent) {
            // Soft-deletes the user; the agent_profile row is retained and the
            // hasOne FK uses nullOnDelete on real engines for invoice references.
            $agent->agentProfile()->delete();
            $agent->delete();

        });
    }
}
