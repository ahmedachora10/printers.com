<?php

namespace App\Actions\Agent;

use App\Enums\Roles;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreateAgentAction
{
    use Concerns\SyncsAgentBranches;

    /** @param array<string, mixed> $data */
    public function handle(array $data): User
    {
        $actor = auth()->user();

        // Branch-scoped actors can only create agents inside their own branch.
        if (! $actor->roleName->isSuperAdmin()) {
            $data['branch_id'] = $actor->branchId;
        }

        return DB::transaction(function () use ($actor, $data) {
            $profile = Arr::only($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'deduct_materials', 'commercial_reg_no']);
            $userData = Arr::except($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'deduct_materials', 'commercial_reg_no', 'branches']);

            // Password is hashed via the User model's 'hashed' cast.
            $user = User::create($userData);
            $user->addRole(Roles::AGENT->value);
            $user->agentProfile()->create($profile);

            // The branch links decide where the agent may be invoiced and on what
            // terms; the profile above only holds the defaults.
            $this->syncAgentBranches($user, $data['branches'] ?? [], $actor);

            Cache::forget('user_role_'.$user->id);

            return $user;
        });
    }
}
