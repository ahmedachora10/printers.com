<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/** تاسك 187 — حساب بنكي للشركة في فرع؛ يُختار في مصروف «تحويل بنكي من حساب الشركة». */
class BankAccount extends Model
{
    use SoftDeletes;

    protected $fillable = ['branch_id', 'name', 'bank_name', 'iban_last4'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** «الراجحي — الرئيسي ••1234» كما يظهر في القوائم والتقارير. */
    public function label(): string
    {
        return implode(' ', array_filter([
            $this->bank_name ? "{$this->bank_name} — {$this->name}" : $this->name,
            $this->iban_last4 ? "••{$this->iban_last4}" : null,
        ]));
    }

    /**
     * خيارات المنتقي: حسابات الفرع، أو كل الفروع للسوبر أدمن (null) مع اسم الفرع —
     * والواجهة تصفّيها بفرع المصروف كما تصفّي الفئات.
     *
     * @return Collection<int, array{id: int, name: string, branchId: int}>
     */
    public static function optionsFor(?int $branchId): Collection
    {
        return self::query()
            ->with('branch:id,name')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get()
            ->map(fn (self $account) => [
                'id' => $account->id,
                'name' => $branchId === null ? "{$account->label()} — {$account->branch?->name}" : $account->label(),
                'branchId' => $account->branch_id,
            ]);
    }
}
