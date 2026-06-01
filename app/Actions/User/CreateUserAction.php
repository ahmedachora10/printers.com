<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreateUserAction
{
    public function handle(array $data): User
    {
        $actor = auth()->user();

        // Branch-scoped actors can only create users inside their own branch.
        if (! $actor->roleName->isSuperAdmin()) {
            $data['branch_id'] = $actor->branchId;
        }

        return DB::transaction(function () use ($data) {
            $role = Arr::pull($data, 'role');

            // Password is hashed via the model's 'hashed' cast.
            $user = User::create($data);

            if ($role) {
                $user->addRole($role);
            }

            Cache::forget('user_role_'.$user->id);

            return $user;
        });
    }
}
