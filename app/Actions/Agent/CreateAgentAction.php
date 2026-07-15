<?php

namespace App\Actions\Agent;

use App\Enums\Roles;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreateAgentAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): User
    {
        $actor = auth()->user();

        // Branch-scoped actors can only create agents inside their own branch.
        if (! $actor->roleName->isSuperAdmin()) {
            $data['branch_id'] = $actor->branchId;
        }

        return DB::transaction(function () use ($data) {
            $profile = Arr::only($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'commercial_reg_no']);
            $userData = Arr::except($data, ['agent_type', 'discount_mode', 'discount_type', 'rate', 'commercial_reg_no']);

            // Password is hashed via the User model's 'hashed' cast.
            $user = User::create($userData);
            $user->addRole(Roles::AGENT->value);
            $user->agentProfile()->create($profile);

            Cache::forget('user_role_'.$user->id);

            return $user;
        });
    }
}
