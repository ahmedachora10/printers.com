<?php

namespace App\Models;

use App\Enums\DeductionReasonEnum;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeDeductionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * تاسك 74: حسمٌ مطبَّق على موظف بسببه وقيمته.
 *
 * لا يُحدَّث بعد الإدراج — لا تُعاد كتابة قيمةٍ أو سببٍ رآهما الموظف في إشعاره.
 * لكنه يُحذف: الحسم بندٌ مستقلّ لا تُبنى عليه فاتورةٌ ولا صفُّ عمولة، فإلغاؤه
 * بقيدٍ معاكس كان يُضاعف السطور في كشفٍ يقرؤه الموظف نفسه. والحذف `SoftDeletes`،
 * فيختفي من كل عرضٍ ومجموع ويبقى أثره للمراجعة.
 */
class EmployeeDeduction extends Model
{
    /** @use HasFactory<EmployeeDeductionFactory> */
    use HasFactory;

    use SoftDeletes;

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
