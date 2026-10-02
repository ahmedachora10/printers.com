<?php

namespace App\Imports\Sheets;

use App\Actions\AgentService\SyncAgentServiceCommissionsAction;
use App\Enums\LineAgentCommissionTypeEnum;
use App\Imports\Concerns\ReadsArabicHeadings;
use App\Models\Agent;
use App\Models\BranchService;
use App\Support\Import\ImportReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * الورقة الثالثة (تاسك 153): عمولة مندوبٍ بعينه على خدمةٍ بعينها.
 *
 * على منوال {@see BranchServiceCommissionsSheetImport}: **نوعٌ أُفرغ عمداً يحذف
 * الإعداد** فيعود الموظف يكتب العمولة يدوياً، وغياب المندوب عن الورقة لا يمسّه.
 * المندوب يُطابَق باسم المستخدم ثم بالاسم بين مناديب الفرع (agent_branch).
 */
class BranchServiceAgentCommissionsSheetImport implements ToCollection, WithHeadingRow
{
    use ReadsArabicHeadings;

    public const SERVICE = ['الخدمة', 'اسم الخدمة', 'service'];

    public const AGENT = ['المندوب', 'اسم المندوب', 'agent'];

    public const USERNAME = ['اسم المستخدم', 'username'];

    public const TYPE = ['نوع العمولة', 'commission_type'];

    public const VALUE = ['قيمة العمولة', 'commission_value'];

    /** @var array<string, BranchService|null> */
    private array $serviceCache = [];

    /** @var array<string, int|false|null> */
    private array $agentCache = [];

    public function __construct(
        private readonly int $branchId,
        private readonly ImportReport $report,
    ) {}

    /** @param  Collection<int, Collection<string, mixed>>  $rows */
    public function collection(Collection $rows): void
    {
        $sync = app(SyncAgentServiceCommissionsAction::class);

        foreach ($rows as $index => $row) {
            $this->importRow($row, $index + 2, $sync);
        }
    }

    /** @param  Collection<string, mixed>  $row */
    private function importRow(Collection $row, int $number, SyncAgentServiceCommissionsAction $sync): void
    {
        if ($row->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
            return;
        }

        $serviceName = $this->cell($row, self::SERVICE);
        $agentName = $this->cell($row, self::AGENT);
        $username = $this->cell($row, self::USERNAME);
        $label = trim(($serviceName ?? '—').' — '.($agentName ?? $username ?? '—'));

        if ($serviceName === null || ($agentName === null && $username === null)) {
            $this->report->skip($number, $label, 'الصف بلا اسم خدمة أو بلا مندوب');

            return;
        }

        $service = $this->resolveService($serviceName);

        if ($service === null) {
            $this->report->skip($number, $label, 'الخدمة غير مرتبطة بهذا الفرع: '.$serviceName);

            return;
        }

        $agentId = $this->resolveAgent($username, $agentName);

        if ($agentId === false) {
            $this->report->skip($number, $label, 'أكثر من مندوب بهذا الاسم — استخدم عمود «اسم المستخدم»');

            return;
        }

        if ($agentId === null) {
            $this->report->skip($number, $label, 'مندوب غير مرتبط بهذا الفرع: '.($username ?? $agentName));

            return;
        }

        $rawType = $this->cell($row, self::TYPE);
        $type = $rawType === null ? null : $this->parseType($rawType);

        if ($rawType !== null && $type === null) {
            $this->report->skip($number, $label, 'نوع عمولة غير معروف: '.$rawType);

            return;
        }

        $value = $this->money($row, self::VALUE);

        if ($type !== null) {
            if ($value === false || $value === null || $value < 0) {
                $this->report->skip($number, $label, 'قيمة العمولة مفقودة أو غير صالحة');

                return;
            }

            if ($type === LineAgentCommissionTypeEnum::Percentage && $value > 100) {
                $this->report->skip($number, $label, 'النسبة يجب ألا تتجاوز 100');

                return;
            }

            if ($type === LineAgentCommissionTypeEnum::PerSqm && $service->pricing_type?->isMeasured() !== true) {
                $this->report->skip($number, $label, 'عمولة وحدة القياس للخدمات المسعّرة بالمتر فقط');

                return;
            }
        }

        $sync->handle($service->id, [[
            'agent_id' => $agentId,
            'commission_type' => $type?->value,
            'commission_value' => $type === null ? null : $value,
        ]]);

        $this->report->count($type === null ? 'commissionsCleared' : 'agentCommissionsSet');
        $this->report->row($number, $label, 'update');
    }

    /** يقبل التسمية العربية كما يكتبها التصدير، أو القيمة اللاتينية. */
    private function parseType(string $raw): ?LineAgentCommissionTypeEnum
    {
        foreach (LineAgentCommissionTypeEnum::cases() as $case) {
            if ($raw === $case->label() || mb_strtolower($raw) === $case->value) {
                return $case;
            }
        }

        return null;
    }

    private function resolveService(string $name): ?BranchService
    {
        if (! array_key_exists($name, $this->serviceCache)) {
            $this->serviceCache[$name] = BranchService::query()
                ->where('branch_id', $this->branchId)
                ->whereHas('serviceTemplate', fn ($query) => $query->where('name', $name))
                ->first();
        }

        return $this->serviceCache[$name];
    }

    /** false حين تعدّد الاسم بين مناديب الفرع، وnull حين لم يُعرف أصلاً. */
    private function resolveAgent(?string $username, ?string $name): int|false|null
    {
        $key = $username ?? '@'.$name;

        if (array_key_exists($key, $this->agentCache)) {
            return $this->agentCache[$key];
        }

        $agents = Agent::query()
            ->forBranch($this->branchId)
            ->when(
                $username !== null,
                fn ($query) => $query->where('username', $username),
                fn ($query) => $query->where('name', $name),
            )
            ->pluck('id');

        return $this->agentCache[$key] = match (true) {
            $agents->count() > 1 => false,
            $agents->isEmpty() => null,
            default => (int) $agents->first(),
        };
    }
}
