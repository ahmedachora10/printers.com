<?php

namespace App\Models;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'branch_id',
        'customer_type',
        'company_name',
        'credit_limit',
        'agent_id',
        'points_balance',
        'cumulative_spend',
        'tier',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'customer_type'    => CustomerTypeEnum::class,
        'tier'             => CustomerTierEnum::class,
        'credit_limit'     => 'decimal:2',
        'cumulative_spend' => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Branch, self> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, self> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
