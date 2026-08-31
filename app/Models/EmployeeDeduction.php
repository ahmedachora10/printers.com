<?php

namespace App\Models;

use App\Enums\DeductionReasonEnum;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeDeductionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تاسك 74: حسمٌ مطبَّق على موظف بسببه وقيمته.
 *
 * IMMUTABLE after insert — لا تُحدَّث ولا تُحذف؛ الإلغاء يكون بقيدٍ معاكس، كما في
 * `bonus_payments` و`commission_ledger`.
 */
class EmployeeDeduction extends Model
{
    /** @use HasFactory<EmployeeDeductionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'amount',
        'reason',
        'reason_note',
        'deducted_by',
        'deducted_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reason' => DeductionReasonEnum::class,
        'deducted_at' => 'datetime',
    ];

    /**
     * الخصومات الواقعة داخل مدى تاريخين — نظير `IncentivePlan::inPeriodRange`،
     * إلا أن للحسم تاريخاً فعلياً فيُقارَن به مباشرة.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDeductedBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): void
    {
        $query
            ->when($from, fn(Builder $q) => $q->where('deducted_at', '>=', $from))
            ->when($to, fn(Builder $q) => $q->where('deducted_at', '<=', $to));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deductedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deducted_by');
    }

    /** السبب كما يُقرأ: التسمية المغلقة، ونصّ «أخرى» حين كُتب. */
    public function reasonLabel(): string
    {
        return $this->reason->requiresNote() && $this->reason_note
            ? $this->reason->label() . ' — ' . $this->reason_note
            : $this->reason->label();
    }
}
