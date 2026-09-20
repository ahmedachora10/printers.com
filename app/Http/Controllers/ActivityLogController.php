<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsPagedProps;
use App\Http\Resources\Activity\ActivityResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * تاسك 115: سجلّ حركة العمليات. شاشتان على مصدرٍ واحد (`activity_log`):
 * جدولٌ عامّ لكل الفاعلين، وخطٌّ زمنيّ لمستخدمٍ واحد.
 *
 * لا يُكتب هنا شيء — الكتابة قائمةٌ أصلاً عبر `LogsActivity` على النماذج
 * و`activity()` في المسارات اليدوية. النطاق بالفاعل لا بالفرع: الجدول بلا
 * `branch_id`، فمديرُ الفرع يقرأ ما فعله مستخدمو فرعه.
 */
class ActivityLogController extends Controller
{
    use BuildsPagedProps;

    private const PER_PAGE = 30;

    /** المدى الافتراضي: آخر 30 يوماً — السجلّ يُقرأ للمتابعة لا ليومٍ بعينه. */
    private const DEFAULT_DAYS = 30;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();
        $query = $this->scopedQuery($request, $actor);

        if ($request->filled('user')) {
            $query->where('causer_id', (int) $request->input('user'));
        }

        return Inertia::render('activity-log/index', [
            'activities' => $this->pagedProp(
                $query->paginate(self::PER_PAGE)->withQueryString(),
                fn (Activity $activity) => (new ActivityResource($activity))->toArray($request),
            ),
            'users' => $this->visibleUsers($actor),
            'logOptions' => $this->logOptions(),
            'filters' => $this->filters($request),
            'defaultFrom' => $this->defaultFrom(),
            'defaultTo' => Carbon::today()->format('Y-m-d'),
        ]);
    }

    public function forUser(Request $request, User $user): Response
    {
        Gate::authorize('viewActivity', $user);

        $query = $this->scopedQuery($request, $request->user())
            ->where('causer_id', $user->id);

        return Inertia::render('users/activity', [
            'subject' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'roleLabel' => $user->roleName?->label(),
                'branchName' => $user->workBranch()?->name,
                'isActive' => (bool) $user->is_active,
            ],
            'activities' => $this->pagedProp(
                $query->paginate(self::PER_PAGE)->withQueryString(),
                fn (Activity $activity) => (new ActivityResource($activity))->toArray($request),
            ),
            'logOptions' => $this->logOptions(),
            'filters' => $this->filters($request),
            'defaultFrom' => $this->defaultFrom(),
            'defaultTo' => Carbon::today()->format('Y-m-d'),
        ]);
    }

    /**
     * السجلّ ضمن صلاحية القارئ ومدى التصفية. مديرُ الفرع محصورٌ بفاعلي فرعه،
     * ونفسِه — فصفُّه هو قد لا يحمل `branch_id` أصلاً (يُقرأ من `branches.owner_id`).
     *
     * @return Builder<Activity>
     */
    private function scopedQuery(Request $request, User $actor): Builder
    {
        $query = Activity::query()
            ->with(['causer:id,name', 'subject'])
            ->latest('id');

        if (! $actor->roleName?->isSuperAdmin()) {
            $branchUsers = User::query()
                ->where('branch_id', $actor->branchId)
                ->select('id');

            $query->where('causer_type', User::class)
                ->where(fn (Builder $q) => $q->whereIn('causer_id', $branchUsers)->orWhere('causer_id', $actor->id));
        }

        $filters = $this->filters($request);

        // ponytail: لا فهرس على `created_at` — الشاشة العامّة تمسح ما تصفّيه
        // بالتاريخ. مقبولٌ بحجم الجدول اليوم؛ يُضاف فهرسٌ إن ثقُل. وسجلّ
        // مستخدمٍ واحد مفهرسٌ بالفاعل أصلاً وهو الاستعمال الغالب.
        $query->whereDate('created_at', '>=', $filters['from'])
            ->whereDate('created_at', '<=', $filters['to']);

        if ($filters['log'] !== 'all') {
            // اسمان مختلفان قد يحملان العنوان نفسه (`customer` و`customers`)،
            // فالتصفية بالعنوان لا بالاسم وإلا اختفى نصفُ القسم.
            $label = ActivityResource::LOG_LABELS[$filters['log']] ?? null;

            $query->whereIn('log_name', $label
                ? array_keys(ActivityResource::LOG_LABELS, $label, true)
                : [$filters['log']]);
        }

        if ($filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';

            $query->where(fn (Builder $q) => $q
                ->where('description', 'like', $search)
                ->orWhereIn('causer_id', User::query()->where('name', 'like', $search)->select('id')));
        }

        return $query;
    }

    /** @return array{from: string, to: string, log: string, search: string, user: string} */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->filled('from') ? (string) $request->input('from') : $this->defaultFrom(),
            'to' => $request->filled('to') ? (string) $request->input('to') : Carbon::today()->format('Y-m-d'),
            'log' => (string) $request->input('log', 'all'),
            'search' => trim((string) $request->input('search', '')),
            'user' => (string) $request->input('user', 'all'),
        ];
    }

    private function defaultFrom(): string
    {
        return Carbon::today()->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d');
    }

    /**
     * الأقسام الموجودة فعلاً في الجدول — `log_name` مفهرس، فالاستعلام رخيص.
     *
     * @return list<array{value: string, label: string}>
     */
    private function logOptions(): array
    {
        return Activity::query()
            ->select('log_name')
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name')
            ->map(fn (string $name) => [
                'value' => $name,
                'label' => ActivityResource::LOG_LABELS[$name] ?? $name,
            ])
            ->unique('label')
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function visibleUsers(User $actor): array
    {
        return User::query()
            ->when(! $actor->roleName?->isSuperAdmin(), fn ($q) => $q->where('branch_id', $actor->branchId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
            ->all();
    }
}
