<?php

namespace App\Models\Concerns;

use App\Enums\InvoiceTypeEnum;
use App\Models\InvoicePayment;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Shared payment-receipt media handling for everything that carries a transfer
 * proof: the two invoice models, an invoice payment and a refund. Receipts are
 * private files served only through the authorization-gated receipt routes —
 * never a public URL.
 *
 * تاسك 229: حتى MAX_RECEIPTS إيصالات لكل موضع؛ الجديد يُضاف ولا يستبدل.
 */
trait HasReceiptMedia
{
    public const RECEIPT_COLLECTION = 'receipt';

    public const MAX_RECEIPTS = 5;

    /**
     * The accepted receipt mime types — registered by each model's
     * registerMediaCollections() (a class method, to avoid the trait collision
     * with InteractsWithMedia::registerMediaCollections).
     *
     * @var list<string>
     */
    public const RECEIPT_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /**
     * قواعد حقل `receipts` الواحدة لكل طلبٍ يقبل إيصالات — رسائلها في lang/ar/validation.php،
     * والواجهة مرآتها في receipt-field.tsx.
     *
     * @return array<string, array<int, string>>
     */
    public static function receiptRules(bool $required): array
    {
        return [
            'receipts' => [$required ? 'required' : 'nullable', 'array', 'max:'.self::MAX_RECEIPTS],
            'receipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    /**
     * يضيف الإيصالات إلى ما هو مرفق. الحدّ يُقاس على المجموع (المرفق + الجديد)،
     * فيُستدعى داخل معاملة الحفظ ليتراجع كل شيء إن تجاوزه.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function addReceipts(array $files): void
    {
        if ($files !== [] && $this->getMedia(self::RECEIPT_COLLECTION)->count() + count($files) > self::MAX_RECEIPTS) {
            throw ValidationException::withMessages(['receipts' => __('validation.custom.receipts.max')]);
        }

        foreach ($files as $file) {
            $this->addMedia($file)->toMediaCollection(self::RECEIPT_COLLECTION);
        }
    }

    /** الإيصال المطلوب بمعرّفه من إيصالات هذا السجلّ وحده، أو الأول. */
    public function receipt(?int $mediaId = null): ?Media
    {
        return $mediaId
            ? $this->getMedia(self::RECEIPT_COLLECTION)->firstWhere('id', $mediaId)
            : $this->getFirstMedia(self::RECEIPT_COLLECTION);
    }

    public function hasReceipt(): bool
    {
        return $this->receipt() !== null;
    }

    /**
     * Authorization-gated URL for the first stored receipt, or null when none.
     */
    public function receiptUrl(): ?string
    {
        if (! $this->hasReceipt()) {
            return null;
        }

        return match (true) {
            $this instanceof InvoicePayment => route('invoices.payments.receipt', ['payment' => $this->id]),
            $this instanceof Refund => route('refunds.receipt', ['refund' => $this->id]),
            default => route('invoices.receipt', [
                'type' => ($this instanceof ServiceInvoice ? InvoiceTypeEnum::SERVICE : InvoiceTypeEnum::PRODUCT)->value,
                'id' => $this->id,
            ]),
        };
    }

    /**
     * كل الإيصالات بروابطها المحميّة.
     *
     * @return list<array{id: int, name: string, url: string}>
     */
    public function receiptUrls(): array
    {
        $base = $this->receiptUrl();

        return $this->getMedia(self::RECEIPT_COLLECTION)
            ->map(fn (Media $media) => ['id' => $media->id, 'name' => $media->file_name, 'url' => $base.'?media='.$media->id])
            ->values()
            ->all();
    }
}
