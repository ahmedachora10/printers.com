<?php

namespace App\Imports\Sheets;

use App\Actions\UserService\SyncUserServiceCommissionsAction;
use App\Enums\Roles;
use App\Imports\Concerns\ReadsArabicHeadings;
use App\Models\BranchService;
use App\Models\User;
use App\Support\Import\ImportReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * الورقة الثانية: نسبة عمولة موظفٍ بعينه على خدمةٍ بعينها (تاسك M15).
 *
 * لا صفّ = صفر بالمئة لذلك الموظف على تلك الخدمة (الاحتياطي الصفري)، ولذلك
 * **خليّةٌ أُفرغت عمداً تحذف السطر** ولا تُتجاهل: هي الطريقة الوحيدة لقول «أوقفوا
 * عمولة هذا الموظف على هذه الخدمة» من داخل الملف. وغيابُ الموظف عن الورقة لا
 * يعني ذلك — الاستيراد لا يمسّ إلا ما ورد فيه.
 *
 * الموظف يُطابَق باسم المستخدم أولاً لأنه فريد في الجدول كلّه، ثم بالاسم داخل
 * الفرع؛ واسمان متطابقان في فرعٍ واحد يُوقفان الصف بدل أن يُخمَّن أيهما المقصود.
 */
class BranchServiceCommissionsSheetImport implements ToCollection, WithHeadingRow
{
    use ReadsArabicHeadings;

    public const SERVICE = ['الخدمة', 'اسم الخدمة', 'service'];

    public const EMPLOYEE = ['الموظف', 'اسم الموظف', 'employee'];

    public const USERNAME = ['اسم المستخدم', 'username'];

    public const COMMISSION = ['نسبة العمولة', 'العمولة', 'commission_pct'];

    /** @var array<string, int|null> */
    private array $serviceCache = [];

    /** @var array<string, int|false|null> */
    private array $employeeCache = [];

    public function __construct(
        private readonly int $branchId,
        private readonly ImportReport $report,
    ) {}

    /** @param  Collection<int, Collection<string, mixed>>  $rows */
    public function collection(Collection $rows): void
    {
        $sync = app(SyncUserServiceCommissionsAction::class);

        foreach ($rows as $index => $row) {
            $this->importRow($row, $index + 2, $sync);
        }
    }

    /** @param  Collection<string, mixed>  $row */
    private function importRow(Collection $row, int $number, SyncUserServiceCommissionsAction $sync): void
    {
        if ($row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
            return; // صفٌّ فارغ في ذيل الورقة
        }

        $serviceName = $this->cell($row, self::SERVICE);
        $employeeName = $this->cell($row, self::EMPLOYEE);
        $username = $this->cell($row, self::USERNAME);
        $label = trim(($serviceName ?? '—').' — '.($employeeName ?? $username ?? '—'));

        if ($serviceName === null || ($employeeName === null && $username === null)) {
            $this->report->skip($number, $label, 'الصف بلا اسم خدمة أو بلا موظف');

            return;
        }

        // الورقة الأولى تُستورد قبل هذه، فالخدمة التي أنشأها الملف نفسه موجودة
        // هنا — لكن الذاكرة المؤقتة تُملأ بعد ذلك الاستيراد لا قبله.
        $branchServiceId = $this->resolveService($serviceName);

        if ($branchServiceId === null) {
            $this->report->skip($number, $label, 'الخدمة غير مرتبطة بهذا الفرع: '.$serviceName);

            return;
        }

        $userId = $this->resolveEmployee($username, $employeeName);

        if ($userId === false) {
            $this->report->skip($number, $label, 'أكثر من موظف بهذا الاسم — استخدم عمود «اسم المستخدم»');

            return;
        }

        if ($userId === null) {
            $this->report->skip($number, $label, 'موظف غير معروف في هذا الفرع: '.($username ?? $employeeName));

            return;
        }

        $commission = $this->money($row, self::COMMISSION);

        if ($commission === false) {
            $this->report->skip($number, $label, 'نسبة عمولة غير رقمية');

            return;
        }

        if ($commission !== null && ($commission < 0 || $commission > 100)) {
            $this->report->skip($number, $label, 'نسبة العمولة يجب أن تكون بين 0 و100');

            return;
        }

        $sync->handle([[
            'user_id' => $userId,
            'branch_service_id' => $branchServiceId,
            'commission_pct' => $commission,
        ]]);

        $this->report->count($commission === null ? 'commissionsCleared' : 'commissionsSet');
        $this->report->row($number, $label, 'update');
    }

    private function resolveService(string $name): ?int
    {
        return $this->serviceCache[$name] ??= BranchService::query()
            ->where('branch_id', $this->branchId)
            ->whereHas('serviceTemplate', fn ($query) => $query->where('name', $name))
            ->value('id');
    }

    /** false حين تعدّد الاسم داخل الفرع، وnull حين لم يُعرف أصلاً. */
    private function resolveEmployee(?string $username, ?string $name): int|false|null
    {
        $key = $username ?? '@'.$name;

        if (array_key_exists($key, $this->employeeCache)) {
            return $this->employeeCache[$key];
        }

        $employees = User::query()
            ->where('branch_id', $this->branchId)
            ->whereHas('roles', fn ($query) => $query->where('name', Roles::EMPLOYEE->value))
            ->when(
                $username !== null,
                fn ($query) => $query->where('username', $username),
                fn ($query) => $query->where('name', $name),
            )
            ->pluck('id');

        return $this->employeeCache[$key] = match (true) {
            $employees->count() > 1 => false,
            $employees->isEmpty() => null,
            default => (int) $employees->first(),
        };
    }
}
