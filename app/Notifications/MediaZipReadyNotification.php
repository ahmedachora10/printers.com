<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** ملف ZIP كبير بُني في الطابور (BuildMediaZipJob): جاهزٌ برابطه، أو تعذّر بلا رابط. */
class MediaZipReadyNotification extends Notification
{
    public const TYPE = 'media_zip_ready';

    public function __construct(
        private readonly string $zipName,
        private readonly ?string $uuid = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        if ($this->uuid === null) {
            return [
                'type' => 'media_zip_failed',
                'title' => 'تعذّر تجهيز الملف',
                'body' => "تعذّر تجهيز {$this->zipName} — أعد المحاولة أو ضيّق التصفية.",
                'url' => null,
                'icon' => 'ShieldAlert',
            ];
        }

        return [
            'type' => self::TYPE,
            'title' => 'الملف جاهز للتنزيل',
            'body' => "{$this->zipName} — متاح للتنزيل لمدة 24 ساعة.",
            'url' => route('media-zips.show', $this->uuid),
            'icon' => 'FileText',
        ];
    }
}
