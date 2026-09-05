<?php

namespace App\Support;

/**
 * الأوامر المفردة التي تُشغَّل من الشاشة — قائمةٌ مغلقة، لا حقل نصّ.
 *
 * حقلٌ يكتب فيه المستعمل أمراً هو تنفيذُ شيفرةٍ عن بُعد بصلاحية الخادم كاملة،
 * ولو خلف دورٍ ومفتاح. فالمفتاح هنا هو ما يُرسَل، والأمر نفسه مكتوبٌ في هذا
 * الملف لا في الطلب.
 *
 * `backup` تعني: خُذ نسخةً قبله. و`destructive` للتنبيه في الشاشة وحده —
 * الحارس الحقيقي هو أنّ ما ليس في هذه القائمة لا يُنفَّذ.
 */
class DeployTasks
{
    public const GROUPS = [
        'database' => 'قاعدة البيانات',
        'cache' => 'الذاكرة المؤقتة والتخزين',
        'maintenance' => 'الصيانة والطوابير',
        'app' => 'أوامر التطبيق',
        'packages' => 'الحزم',
    ];

    /**
     * @return array<string, array{
     *     label: string,
     *     command: string,
     *     display: string,
     *     group: string,
     *     hint: string,
     *     destructive: bool,
     *     backup: bool,
     *     branch: bool,
     * }>
     */
    public static function all(): array
    {
        return [
            // ── قاعدة البيانات ───────────────────────────────────────────
            'migrate' => self::task(
                label: 'تشغيل الهجرات',
                command: 'migrate',
                display: 'php artisan migrate --force',
                group: 'database',
                hint: 'يُطبّق ما استُجدّ من هجرات. تُؤخذ نسخةٌ احتياطية قبله.',
                destructive: true,
                backup: true,
            ),
            'migrate-status' => self::task(
                label: 'حالة الهجرات',
                command: 'migrate:status',
                display: 'php artisan migrate:status',
                group: 'database',
                hint: 'يعرض ما نُفِّذ وما لم يُنفَّذ. قراءةٌ فقط.',
            ),
            'seed' => self::task(
                label: 'زرع المحدَّد',
                command: 'db:seed',
                display: 'php artisan db:seed --class=…',
                group: 'database',
                hint: 'يُشغّل ما تختاره من الزارعات وحده، دون بقية خطوات النشر.',
                destructive: true,
                backup: true,
            ),
            'backup' => self::task(
                label: 'نسخة احتياطية',
                command: 'app:backup',
                display: 'نسخة احتياطية لقاعدة البيانات',
                group: 'database',
                hint: 'تُكتب في storage/app/backups ويُحتفظ بآخر عشر نسخ.',
            ),

            // ── الذاكرة المؤقتة والتخزين ─────────────────────────────────
            'optimize' => self::task(
                label: 'بناء الذاكرة المؤقتة',
                command: 'optimize',
                display: 'php artisan optimize',
                group: 'cache',
                hint: 'config وroutes وviews وevents.',
            ),
            'optimize-clear' => self::task(
                label: 'مسح الذاكرة المؤقتة',
                command: 'optimize:clear',
                display: 'php artisan optimize:clear',
                group: 'cache',
                hint: 'أول ما يُجرَّب بعد تعديلٍ لم يظهر أثره.',
            ),
            'storage-link' => self::task(
                label: 'ربط مجلد التخزين',
                command: 'storage:link',
                display: 'php artisan storage:link',
                group: 'cache',
                hint: 'يُنشئ public/storage. بعض الاستضافات تمنع الروابط الرمزية.',
            ),

            // ── الصيانة والطوابير ────────────────────────────────────────
            'down' => self::task(
                label: 'إغلاق الموقع',
                command: 'down',
                display: 'php artisan down --retry=60',
                group: 'maintenance',
                hint: 'يُغلق الموقع في وجه الزوار حتى تفتحه بنفسك. هذه الشاشة تبقى عاملة.',
                destructive: true,
            ),
            'up' => self::task(
                label: 'فتح الموقع',
                command: 'up',
                display: 'php artisan up',
                group: 'maintenance',
                hint: 'يرفع وضع الصيانة.',
            ),
            'queue-restart' => self::task(
                label: 'إعادة تشغيل العمّال',
                command: 'queue:restart',
                display: 'php artisan queue:restart',
                group: 'maintenance',
                hint: 'يُشير للعمّال بالخروج ليلتقط cron الكود الجديد.',
            ),
            'queue-failed' => self::task(
                label: 'المهام الفاشلة',
                command: 'queue:failed',
                display: 'php artisan queue:failed',
                group: 'maintenance',
                hint: 'يسرد ما سقط من المهام. قراءةٌ فقط.',
            ),
            'queue-retry' => self::task(
                label: 'إعادة المهام الفاشلة',
                command: 'queue:retry',
                display: 'php artisan queue:retry all',
                group: 'maintenance',
                hint: 'يُعيد كل مهمةٍ فاشلة إلى الطابور.',
            ),

            // ── أوامر التطبيق ────────────────────────────────────────────
            'loyalty-expire' => self::task(
                label: 'تصفير نقاط الخاملين',
                command: 'loyalty:expire-points',
                display: 'php artisan loyalty:expire-points',
                group: 'app',
                hint: 'مجدولٌ يومياً 02:00 — لا تُشغّله إلا لسببٍ يخصّك.',
                destructive: true,
                branch: true,
            ),
            'loyalty-tiers' => self::task(
                label: 'إعادة بناء فئات الولاء',
                command: 'loyalty:recalculate-tiers',
                display: 'php artisan loyalty:recalculate-tiers',
                group: 'app',
                hint: 'يُعيد حساب الإنفاق التراكمي والفئة لكل عميل من فواتيره المدفوعة.',
                destructive: true,
                branch: true,
            ),
            'delivery-reminders' => self::task(
                label: 'تذكير مواعيد التسليم',
                command: 'invoices:notify-upcoming-deliveries',
                display: 'php artisan invoices:notify-upcoming-deliveries',
                group: 'app',
                hint: 'يُرسل إشعارات فعلية للموظفين. مجدولٌ يومياً 08:00.',
            ),
            'review-sqm-materials' => self::task(
                label: 'مراجعة خامات م²',
                command: 'services:review-sqm-materials',
                display: 'php artisan services:review-sqm-materials',
                group: 'app',
                hint: 'يسرد خدمات المتر المربع التي لها تكلفة خامات. قراءةٌ فقط.',
            ),

            // ── الحزم ────────────────────────────────────────────────────
            'composer-install' => self::task(
                label: 'تحديث الحزم',
                command: 'app:composer-install',
                display: 'composer install --no-dev --optimize-autoloader',
                group: 'packages',
                hint: 'يُثبّت ما في ملف القفل. يطول قليلاً.',
            ),
            'deploy-dry-run' => self::task(
                label: 'عرض مجرّد للنشر',
                command: 'app:deploy',
                display: 'php artisan app:deploy --dry-run',
                group: 'packages',
                hint: 'يعرض خطة النشر وعوائقها دون تنفيذ شيء.',
            ),
        ];
    }

    /**
     * @return array{label: string, command: string, display: string, group: string, hint: string, destructive: bool, backup: bool, branch: bool}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * ما يُرسل إلى الشاشة: المفتاح مع وصفه، دون شيءٍ يُنفَّذ منه مباشرة.
     *
     * @return list<array<string, mixed>>
     */
    public static function forDisplay(): array
    {
        return collect(self::all())
            ->map(fn (array $task, string $key): array => ['key' => $key] + $task)
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, command: string, display: string, group: string, hint: string, destructive: bool, backup: bool, branch: bool}
     */
    private static function task(
        string $label,
        string $command,
        string $display,
        string $group,
        string $hint,
        bool $destructive = false,
        bool $backup = false,
        bool $branch = false,
    ): array {
        return compact('label', 'command', 'display', 'group', 'hint', 'destructive', 'backup', 'branch');
    }
}
