<?php

namespace App\Models;

use App\Enums\ExpenseSourceEnum;
use Closure;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Expense extends Model implements HasMedia
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** تاسك 112 — مستندات إثبات المصروف على القرص الخاص (عدّة ملفات منذ تاسك 187). */
    public const ATTACHMENT = 'attachment';

    protected $fillable = [
        'expense_category_id',
        'branch_id',
        'delivery_provider_id',
        'user_id',
        'requested_by',
        'qty',
        'unit_price',
        'total',
        'paid_from',
        'bank_account_id',
        'supplier_name',
        'receipt_reference',
        'comment',
        'date',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_from' => ExpenseSourceEnum::class,
        'date' => 'date',
        'approved_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        // تاسك 113: القديم والجديد للحقول المتغيّرة وحدها — سجلّ تعديلات المعتمد.
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('expenses');
    }

    /** تاسك 113 — approved_* خارج fillable عمداً: لا يكتبهما نموذج التعديل. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** تاسك 157 — طلب موظفٍ لم يقبله المحاسب بعد: لا يُحسب في أي مجموع. */
    public function isPendingRequest(): bool
    {
        return $this->requested_by !== null && $this->accepted_at === null;
    }

    /**
     * تاسك 157 — شرط «يُحسب»: مصروفٌ عادي أو طلبٌ مقبول. يعمل على Eloquent وعلى
     * DB::table معاً (`->where(Expense::counted())`)، فكل مجموعٍ للمصروفات يمرّ به.
     */
    public static function counted(): Closure
    {
        return fn ($q) => $q->whereNull('expenses.requested_by')->orWhereNotNull('expenses.accepted_at');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENT)
            ->useDisk('local')
            ->acceptsMimeTypes(ServiceInvoice::RECEIPT_MIME_TYPES);
    }

    /** @return Collection<int, Media> */
    public function attachments(): Collection
    {
        return $this->getMedia(self::ATTACHMENT);
    }

    /**
     * تاسك 187 — الفواتير المربوطة وحصّة كلٍّ منها من المصروف (`pivot->amount`).
     *
     * @return BelongsToMany<ServiceInvoice, $this>
     */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(ServiceInvoice::class, 'expense_service_invoice')->withPivot('amount');
    }

    /**
     * يكتب روابط الفواتير كما أُرسلت: فاتورةٌ واحدة تأخذ المبلغ كاملاً، وأكثر منها
     * بحصصها (تحقّق مجموعها في ExpenseLinkRules).
     *
     * @param  array<int, array{id: int|string, amount?: numeric-string|float|null}>  $links
     */
    public function syncInvoices(array $links): void
    {
        $this->invoices()->sync(collect($links)->mapWithKeys(fn (array $link) => [
            (int) $link['id'] => ['amount' => count($links) === 1 ? $this->total : $link['amount']],
        ])->all());
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
