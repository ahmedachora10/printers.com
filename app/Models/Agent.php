<?php

namespace App\Models;

use App\Enums\Roles;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Builder;

/**
 * An Agent is a User that holds the `agent` role. This model exposes only those
 * users via a global scope, so route-model binding on /agents/{agent} resolves
 * exclusively to agent users (and 404s for any other user id).
 */
class Agent extends User
{
    protected $table = 'users';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('agent', function (Builder $query) {
            $query->whereHas('roles', fn (Builder $q) => $q->where('name', Roles::AGENT->value));
        });
    }

    protected static function newFactory(): AgentFactory
    {
        return AgentFactory::new();
    }

    /**
     * Agents linked to one branch. This — not users.branch_id — decides where an
     * agent is available; branch_id only records the primary branch. A null
     * branch matches nothing, mirroring the single-branch behaviour it replaces.
     *
     * @param  Builder<covariant Agent>  $query
     */
    public function scopeForBranch(Builder $query, ?int $branchId): void
    {
        $query->whereHas('agentBranches', fn (Builder $q) => $q->where('branches.id', $branchId));
    }

    /**
     * Eager load only the terms for the branch in hand, so `termsForBranch()`
     * resolves from memory.
     *
     * @param  Builder<covariant Agent>  $query
     */
    public function scopeWithBranchTerms(Builder $query, ?int $branchId): void
    {
        $query->with(['agentBranches' => fn ($q) => $q->where('branches.id', $branchId)]);
    }

    /**
     * Agents share the `users` table. Laratrust stores role/permission pivots
     * polymorphically under the User morph type (rows are created as User), so
     * report User here — otherwise the `roles` relation would constrain
     * user_type to "App\Models\Agent" and match nothing.
     */
    public function getMorphClass(): string
    {
        return User::class;
    }
}
