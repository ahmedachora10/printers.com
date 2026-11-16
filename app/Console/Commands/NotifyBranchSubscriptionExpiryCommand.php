<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Notifications\BranchSubscriptionExpiringNotification;
use App\Support\BranchNotifiables;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * تاسك 228: ينبّه مدير كل فرعٍ يتبقّى على نهاية اشتراكه 30 أو 15 أو 7 أيام —
 * يُشغَّل يومياً. إعادة التشغيل في اليوم نفسه لا تكرّر: يُستبعد الفرع الذي سبق
 * أن حمل إشعارٌ له العتبة نفسها وتاريخ النهاية نفسه.
 */
class NotifyBranchSubscriptionExpiryCommand extends Command
{
    public const THRESHOLDS = [30, 15, 7];

    protected $signature = 'branches:notify-subscription-expiry';

    protected $description = 'تنبيه مديري الفروع باقتراب انتهاء الاشتراك (قبل 30 و15 و7 أيام)';

    public function handle(): int
    {
        // نطاقٌ بـ whereDate لا whereIn: SQLite يخزّن التاريخ مع وقتٍ صفريّ فلا يطابقه تاريخٌ مجرّد.
        $branches = Branch::query()
            ->where('is_active', true)
            ->whereDate('subscription_ends_at', '>=', today()->addDays(min(self::THRESHOLDS)))
            ->whereDate('subscription_ends_at', '<=', today()->addDays(max(self::THRESHOLDS)))
            ->get()
            ->filter(fn (Branch $branch) => in_array($branch->subscriptionDaysLeft(), self::THRESHOLDS, true));

        $sent = 0;

        foreach ($branches as $branch) {
            $daysLeft = $branch->subscriptionDaysLeft();
            $endsAt = $branch->subscription_ends_at->toDateString();

            $already = DatabaseNotification::query()
                ->where('type', BranchSubscriptionExpiringNotification::class)
                ->where('data->branchId', $branch->id)
                ->where('data->endsAt', $endsAt)
                ->where('data->daysLeft', $daysLeft)
                ->exists();

            if ($already) {
                continue;
            }

            foreach (BranchNotifiables::forBranch($branch->id, ['branch-admin']) as $admin) {
                $admin->notify(new BranchSubscriptionExpiringNotification($branch->id, $branch->name, $daysLeft, $endsAt));
                $sent++;
            }
        }

        $this->info("تم إرسال {$sent} تنبيهاً باقتراب انتهاء الاشتراك.");

        return self::SUCCESS;
    }
}
