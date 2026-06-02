<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteUserAction
{
    public function handle(User $user): bool
    {
        if ($user->id === auth()->id()) {
            throw ValidationException::withMessages([
                'user' => 'لا يمكنك حذف حسابك الخاص.',
            ]);
        }

        return DB::transaction(function () use ($user) {
            $deleted = $user->delete();

            Cache::forget('user_role_'.$user->id);

            return $deleted;
        });
    }
}
