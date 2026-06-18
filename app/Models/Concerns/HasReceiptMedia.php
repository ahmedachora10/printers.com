<?php

namespace App\Models\Concerns;

use App\Enums\InvoiceTypeEnum;
use App\Models\ServiceInvoice;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Shared payment-receipt media handling for the two invoice models. The
 * receipt (e.g. a bank-transfer proof) is a single private file served only
 * through the authorization-gated receipt route — never a public URL.
 */
trait HasReceiptMedia
{
    public const RECEIPT_COLLECTION = 'receipt';

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

    public function receipt(): ?Media
    {
        return $this->getFirstMedia(self::RECEIPT_COLLECTION);
    }

    public function hasReceipt(): bool
    {
        return $this->receipt() !== null;
    }

    /**
     * Authorization-gated URL for the stored receipt, or null when none.
     */
    public function receiptUrl(): ?string
    {
        if (! $this->hasReceipt()) {
            return null;
        }

        $type = $this instanceof ServiceInvoice
            ? InvoiceTypeEnum::SERVICE
            : InvoiceTypeEnum::PRODUCT;

        return route('invoices.receipt', ['type' => $type->value, 'id' => $this->id]);
    }
}
