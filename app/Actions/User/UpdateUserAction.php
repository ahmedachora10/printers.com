<?php

namespace App\Actions\User;

use App\Actions\Branch\UpdateBranchAction;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UpdateUserAction
{
    public function __construct(
        private UpdateBranchAction $updateBranchAction
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $user, array $data): User
    {
        $actor = Auth::user();

        $role = Arr::pull($data, 'role');

        // Branch-scoped actors keep managed users inside their own branch.
        if (! $actor->roleName->isSuperAdmin() && array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $actor->branchId;
        }

        // Leave the password untouched when not provided.
        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        return DB::transaction(function () use ($user, $data, $role) {
            $user->update($data);

            if ($role && ! $user->hasRole($role)) {
                $user->syncRoles([$role]);
            }

            if ($role === Roles::BRANCH_ADMIN->value && ! empty($data['branch_id'])) {
                // Release any other branch this user currently manages so a
                // manager only ever owns a single branch.
                Branch::query()
                    ->where('owner_id', $user->id)
                    ->where('id', '!=', $data['branch_id'])
                    ->update(['owner_id' => null]);

                $this->updateBranchAction->handle(Branch::find($data['branch_id']), ['owner_id' => $user->id]);
            }

            Cache::forget('user_role_'.$user->id);

            return $user->refresh();
        });
    }
}
