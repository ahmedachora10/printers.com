<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UpdateUserAction
{
    public function handle(User $user, array $data): User
    {
        $actor = auth()->user();

        // Branch-scoped actors keep managed users inside their own branch.
        if (! $actor->roleName->isSuperAdmin() && array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $actor->branchId;
        }

        $role = Arr::pull($data, 'role');

        // Leave the password untouched when not provided.
        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        return DB::transaction(function () use ($user, $data, $role) {
            $user->update($data);

            if ($role && ! $user->hasRole($role)) {
                $user->syncRoles([$role]);
            }

            Cache::forget('user_role_'.$user->id);

            return $user->refresh();
        });
    }
}
