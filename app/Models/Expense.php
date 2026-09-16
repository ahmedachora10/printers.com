<?php

namespace App\Models;

use App\Enums\ExpenseSourceEnum;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Expense extends Model implements HasMedia
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** تاسك 112 — مستند إثبات المصروف، ملفٌّ واحد على القرص الخاص. */
    public const ATTACHMENT = 'attachment';

    protected $fillable = [
        'expense_category_id',
        'branch_id',
        'service_invoice_id',
        'user_id',
        'qty',
        'unit_price',
        'total',
        'paid_from',
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

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENT)
            ->singleFile()
            ->useDisk('local')
            ->acceptsMimeTypes(ServiceInvoice::RECEIPT_MIME_TYPES);
    }

    public function attachment(): ?Media
    {
        return $this->getFirstMedia(self::ATTACHMENT);
    }

    /** @return BelongsTo<ServiceInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
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
