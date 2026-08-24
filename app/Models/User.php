<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Roles;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property-read Roles|null $roleName
 * @property-read int|null $branchId
 * @property-read Collection<int, Role> $roles
 * @property CarbonImmutable|null $joined_date
 */
class User extends Authenticatable implements HasMedia, LaratrustUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory,
        HasRolesAndPermissions,
        InteractsWithMedia,
        Notifiable,
        SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'phone',
        'branch_id',
        'salary',
        'base_commission_pct',
        'referral_commission_pct',
        'joined_date',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Per-request memo behind {@see workBranch()} — not an attribute. */
    protected ?Branch $workBranch = null;

    protected bool $workBranchResolved = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'joined_date' => 'date',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasOne<Branch, $this> */
    public function branchManager(): HasOne
    {
        return $this->hasOne(Branch::class, 'owner_id');
    }

    /** @return HasMany<UserService, $this> */
    public function serviceCommissions(): HasMany
    {
        return $this->hasMany(UserService::class);
    }

    /** @return HasOne<AgentProfile, $this> */
    public function agentProfile(): HasOne
    {
        // Pin the foreign key: this relation is also used on the Agent subclass,
        // where the default would wrongly infer `agent_id`.
        return $this->hasOne(AgentProfile::class, 'user_id');
    }

    /**
     * The branches an agent works with, each carrying its own negotiated terms.
     * Lives here rather than on Agent so the portal — which holds a plain User
     * from the guard — can read it too.
     *
     * @return BelongsToMany<Branch, $this, AgentBranch>
     */
    public function agentBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'agent_branch', 'agent_id', 'branch_id')
            ->using(AgentBranch::class)
            ->withPivot(['id', 'discount_mode', 'discount_type', 'rate'])
            ->withTimestamps();
    }

    /**
     * The terms agreed with one branch, or null when the agent is not linked to
     * it — which is exactly what makes the agent unavailable in that branch.
     * Reads an already-loaded relation when there is one, so callers that eager
     * load a whole POS list do not fire a query per agent.
     */
    public function termsForBranch(int $branchId): ?AgentBranch
    {
        $branch = $this->relationLoaded('agentBranches')
            ? $this->agentBranches->firstWhere('id', $branchId)
            : $this->agentBranches()->where('branches.id', $branchId)->first();

        return $branch?->pivot;
    }

    /** @return Attribute<Roles|null, never> */
    public function roleName(): Attribute
    {
        return Attribute::make(
            get: fn () => Cache::remember('user_role_'.$this->id, now()->addDay(), fn () => Roles::tryFrom($this->roles->first()?->name)),
        );
    }

    /**
     * The branch this user works out of, resolved once and kept on the instance
     * for the rest of the request. The shared Inertia layout and whatever the
     * page itself needs from the branch then cost a single query between them
     * instead of one each.
     *
     * A branch-admin is resolved through `branches.owner_id`; that row is
     * already loaded by the `branchId` accessor, so reusing it here is free.
     */
    public function workBranch(): ?Branch
    {
        if ($this->workBranchResolved) {
            return $this->workBranch;
        }

        $this->workBranchResolved = true;

        if ($this->roleName?->isBranchAdmin()) {
            return $this->workBranch = $this->branchManager;
        }

        $branchId = $this->attributes['branch_id'] ?? null;

        return $this->workBranch = $branchId
            ? Branch::with('media')->find($branchId)
            : null;
    }

    /** @return Attribute<int|null, never> */
    public function branchId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->roleName?->isBranchAdmin()
                ? $this->branchManager?->id
                // Read the raw column directly: this accessor is registered for the
                // `branch_id` key, so `$this->branch_id` would recurse into it.
                : ($this->attributes['branch_id'] ?? null),
        );
    }
}
