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

class CreateUserAction
{
    public function __construct(
        private UpdateBranchAction $updateBranchAction
    )
    {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): User
    {
        $actor = Auth::user();

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

            if( $role === Roles::BRANCH_ADMIN->value) {
                $this->updateBranchAction->handle(Branch::find($data['branch_id']), ['owner_id' => $user->id]);
            }

            Cache::forget('user_role_'.$user->id);

            return $user;
        });
    }
}
