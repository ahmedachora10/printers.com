<?php

namespace App\Notifications;

use App\Enums\InvoiceTypeEnum;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * تاسك 100 — رسالة جديدة في المحادثة الداخلية لفاتورة: لصاحبها، ولمن راسل في
 * خيطها قبلاً، ولمن أُشير إليه. المرسل لا يُنبَّه بنفسه.
 */
class InvoiceMessagePostedNotification extends Notification
{
    public function __construct(
        private readonly string $invoiceNumber,
        private readonly int $invoiceId,
        private readonly string $authorName,
        private readonly ?string $body,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $excerpt = $this->body !== null ? Str::limit($this->body, 80) : 'أرفق ملفاً';

        return [
            'type' => 'invoice_message',
            'title' => "رسالة جديدة على الفاتورة {$this->invoiceNumber}",
            'body' => "{$this->authorName}: {$excerpt}",
            'url' => route('invoices.show', ['type' => InvoiceTypeEnum::SERVICE->value, 'id' => $this->invoiceId]),
            'icon' => 'MessageSquare',
        ];
    }
}
