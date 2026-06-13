<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Branch extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'name',
        'city_id',
        'owner_id',
        'phone',
        'address',
        'business_type',
        'commercial_reg_no',
        'tax_number',
        'vat_rate_override',
        'is_active',
    ];

    protected $casts = [
        'vat_rate_override' => 'decimal:2',
        'is_active'         => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'branch_id');
    }

    /** @return BelongsToMany<ServiceTemplate, $this, BranchService, 'pivot'> */
    public function serviceTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ServiceTemplate::class, 'branch_services')
            ->using(BranchService::class)
            ->withPivot(['id', 'base_commission_pct', 'max_discount_pct', 'is_tahazir', 'is_active'])
            ->withTimestamps();
    }

    /** @return Collection<int, PaymentMethod> */
    public function enabledPaymentMethods(): Collection
    {
        $ids = json_decode(Setting::get('enabled_payment_methods', $this->id, '[]'), true) ?? [];

        $query = PaymentMethod::where('is_active', true);

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        return $query->orderBy('name')->get();
    }
}
