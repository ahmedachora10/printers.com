<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * دفعة واحدة على فاتورة (عربون أو دفعة لاحقة).
 *
 * الجدول للإضافة فقط، على نمط commission_ledger: لا يُعدَّل صف ولا يُحذف بعد
 * إدراجه. تصحيح دفعة خاطئة يكون بإدراج دفعة سالبة مقابلة عبر
 * RecordInvoicePaymentAction، لا بـ UPDATE.
 *
 * تحمل الدفعة إيصالها الخاص: طريقة دفع تشترط إثباتاً (تحويل بنكي مثلاً) توجب
 * رفع الإيصال مع كل دفعة على حدة، لا مرة واحدة على الفاتورة — فالفاتورة قد
 * تُسدَّد على دفعتين بتحويلين مختلفين. الملف على القرص الخاص ولا يُقدَّم إلا من
 * المسار المحمي.
 */
class InvoicePayment extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const RECEIPT_COLLECTION = 'receipt';

    /** @var list<string> */
    public const RECEIPT_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    protected $fillable = [
        'invoice_id',
        'invoice_type',
        'branch_id',
        'payment_method_id',
        'amount',
        'paid_at',
        'recorded_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::RECEIPT_COLLECTION)
            ->singleFile()
            ->useDisk('local')
            ->acceptsMimeTypes(self::RECEIPT_MIME_TYPES);
    }

    public function receipt(): ?Media
    {
        return $this->getFirstMedia(self::RECEIPT_COLLECTION);
    }

    /** رابط الإيصال المحمي بالصلاحية، أو null إن لم يُرفق شيء. */
    public function receiptUrl(): ?string
    {
        return $this->receipt() === null
            ? null
            : route('invoices.payments.receipt', ['payment' => $this->id]);
    }

    /** @return MorphTo<Model, $this> */
    public function invoice(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'invoice_type', 'invoice_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
