<?php

namespace App\Models;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The terms agreed between one agent (مندوب) and one branch. An agent may work
 * with several branches, each on its own rate and discount mode, so these live
 * on the link rather than on the agent.
 *
 * This is the single source of truth the POS, the calculators and the payment
 * run all read — `agent_profiles` only supplies the defaults an operator sees
 * pre-filled when linking a new branch.
 */
class AgentBranch extends Pivot
{
    protected $table = 'agent_branch';

    public $incrementing = true;

    protected $casts = [
        'discount_mode' => AgentDiscountModeEnum::class,
        'discount_type' => AgentDiscountTypeEnum::class,
        'rate' => 'decimal:2',
        // تاسك 69: هل تُطرح تكلفة خامات السطر من قاعدة عمولته (النسبة وحدها)؟
        'deduct_materials' => 'boolean',
    ];
}
