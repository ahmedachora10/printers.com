<?php

namespace App\Actions\UserService;

use App\Models\UserService;
use Illuminate\Support\Facades\DB;

class SyncUserServiceCommissionsAction
{
    /**
     * Upsert (or clear) per-employee commission rates for a set of
     * (user, branch_service) pairs. A pair whose commission_pct is null is
     * removed — the employee then earns 0% on that service (zero fallback).
     *
     * @param  list<array{user_id: int, branch_service_id: int, commission_pct: float|null}>  $pairs
     */
    public function handle(array $pairs): void
    {
        DB::transaction(function () use ($pairs): void {
            foreach ($pairs as $pair) {
                $key = [
                    'user_id' => $pair['user_id'],
                    'branch_service_id' => $pair['branch_service_id'],
                ];

                if ($pair['commission_pct'] === null) {
                    UserService::query()->where($key)->delete();

                    continue;
                }

                UserService::query()->updateOrCreate($key, [
                    'commission_override_pct' => $pair['commission_pct'],
                ]);
            }
        });
    }
}
