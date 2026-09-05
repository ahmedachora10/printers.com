<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * يُخبر المديرين العامّين أنّ أحداً يُجرّب مفاتيح على شاشة النشر.
 *
 * الخنق يردّ المحاولة ولا يُخبر بها أحداً، وسجلّ النشاط لا يُقرأ إلا بعد
 * وقوع شيء. فيُرفع الخبر مرّةً واحدة في النافذة: تنبيهٌ لا إزعاج.
 */
class DeployUnlockAttemptsNotification extends Notification
{
    public function __construct(
        private readonly string $ip,
        private readonly int $attempts,
        private readonly int $windowMinutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'deploy_unlock_attempts',
            'title' => 'محاولات فتحٍ لشاشة النشر',
            'body' => "{$this->attempts} محاولات بمفتاحٍ خاطئ خلال {$this->windowMinutes} دقيقة من العنوان {$this->ip}.",
            'url' => route('deployment.index'),
            'icon' => 'ShieldAlert',
            'ip' => $this->ip,
            'attempts' => $this->attempts,
        ];
    }
}
