<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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

    /** @return BelongsTo<Branch, self> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasOne<Branch, self> */
    public function branchManager(): HasOne
    {
        return $this->hasOne(Branch::class, 'owner_id');
    }

    public function roleName(): Attribute
    {
        return Attribute::make(
            get: fn () => Cache::remember('user_role_'.$this->id, now()->addDay(), fn () => Roles::tryFrom($this->roles->first()?->name)),
        );
    }

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
