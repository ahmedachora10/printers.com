<?php

namespace App\Models;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentTypeEnum;
use Database\Factories\AgentProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    /** @use HasFactory<AgentProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'agent_type',
        'discount_mode',
        'rate',
        'commercial_reg_no',
    ];

    protected $casts = [
        'agent_type' => AgentTypeEnum::class,
        'discount_mode' => AgentDiscountModeEnum::class,
        'rate' => 'decimal:2',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
