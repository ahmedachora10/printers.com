<?php

namespace App\Http\Controllers;

use App\Enums\CustomerTierEnum;
use App\Http\Controllers\Concerns\BuildsPagedProps;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoyaltyController extends Controller
{
    use BuildsPagedProps;

    private const TRANSACTIONS_PER_PAGE = 15;

    private const BRANCHES_PER_PAGE = 10;

    /**
     * لوحة برنامج الولاء.
     *
     * الإعدادات تُحفظ لكل فرع على حدة، فلا معنى لبطاقة «معدل الاكتساب» واحدة على
     * مستوى الشبكة: السوبر أدمن يرى الأرقام مجموعةً عبر الفروع مع جدولٍ يقارن
     * إعدادات كل فرع، وله أن يختار فرعاً بعينه فتصير الشاشة كما يراها مديره. ومن
     * سواه مربوطٌ بفرعه كما كان.
     *
     * الجدولان يُرقَّمان صفحاتٍ باسمين مختلفين (page و branchPage) فلا يحرّك
     * أحدهما الآخر، ويحملان معهما فلتر الفرع فلا يضيع عند التنقّل.
     */
    public function index(Request $request): Response
    {
        $actor = Auth::user();
        $isSuper = $actor->roleName?->isSuperAdmin() ?? false;

        $branchId = $isSuper
            ? ($request->filled('branch') ? (int) $request->input('branch') : null)
            : $actor->branchId;

        $config = $branchId !== null ? LoyaltyConfig::forBranch($branchId) : null;

        // قائمة الفروع تخدم أمرين — قائمة الاختيار وجدول المقارنة — فتُقرأ مرة
        // واحدة وتُمرَّر إلى من يحتاجها.
        $branches = $isSuper
            ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : new Collection;

        $customers = fn () => Customer::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            // السوبر أدمن بلا فرع محدَّد يرى الشبكة كلها؛ ومن سواه بلا فرع (حالة
            // لا ينبغي أن تقع) لا يرى شيئاً بدل أن يرى كل شيء.
            ->when(! $isSuper && $branchId === null, fn ($q) => $q->whereRaw('1 = 0'));

        $tierCounts = $customers()
            ->selectRaw('tier, COUNT(*) as total')
            ->groupBy('tier')
            ->pluck('total', 'tier');

        $tierDistribution = collect(CustomerTierEnum::cases())->map(fn (CustomerTierEnum $tier) => [
            'tier' => $tier->value,
            'label' => $tier->label(),
            'count' => (int) ($tierCounts[$tier->value] ?? 0),
        ])->values();

        // مجموع النقاط وعدد العملاء في استعلام واحد بدل استعلامين.
        $totals = $customers()
            ->selectRaw('COALESCE(SUM(points_balance), 0) as points, COUNT(*) as customers')
            ->first();

        return Inertia::render('loyalty/index', [
            // بلا فرع محدَّد لا توجد إعدادات واحدة تُعرض — الجدول أدناه يقوم مقامها.
            'config' => $config ? [
                'active' => (bool) $config->is_active,
                'earningRate' => (float) $config->earning_rate,
                'redemptionRate' => (float) $config->redemption_rate,
                'minRedemptionPoints' => (int) $config->min_redemption_points,
                'expiryMonths' => $config->expiry_months,
            ] : null,
            'outstandingPoints' => (int) $totals->points,
            'customerCount' => (int) $totals->customers,
            'tierDistribution' => $tierDistribution,
            'transactions' => $this->transactions($branchId, $isSuper),
            'branchConfigs' => $isSuper && $branchId === null
                ? $this->branchConfigs($request, $branches)
                : ['data' => [], 'meta' => null],
            // عدّاد البطاقة يُحسب على الشبكة كلها لا على صفحة الجدول المعروضة.
            'branchSummary' => $isSuper && $branchId === null
                ? ['total' => $branches->count(), 'active' => $this->activeBranchCount($branches)]
                : null,
            'branches' => $branches,
            'isSuperAdmin' => $isSuper,
            'filters' => ['branch' => $branchId !== null ? (string) $branchId : null],
        ]);
    }

    /**
     * سجلّ حركات النقاط، مرقَّم الصفحات.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function transactions(?int $branchId, bool $isSuper): array
    {
        $paginator = LoyaltyTransaction::query()
            ->with('customer:id,full_name,phone,branch_id')
            ->whereHas('customer', function (Builder $q) use ($branchId, $isSuper) {
                $q->when($branchId, fn ($c) => $c->where('branch_id', $branchId))
                    ->when(! $isSuper && $branchId === null, fn ($c) => $c->whereRaw('1 = 0'));
            })
            ->latest('created_at')
            ->paginate(self::TRANSACTIONS_PER_PAGE)
            ->withQueryString();

        return $this->pagedProp($paginator, fn (LoyaltyTransaction $tx) => [
            'id' => $tx->id,
            'customerName' => $tx->customer?->full_name,
            'customerPhone' => $tx->customer?->phone,
            'type' => $tx->type->value,
            'typeLabel' => $tx->type->label(),
            'points' => $tx->points,
            'balanceAfter' => $tx->balance_after,
            'createdAt' => $tx->created_at->format('Y-m-d H:i'),
        ]);
    }

    /**
     * إعدادات الولاء لكل فرع نشط، ومعها نقاطه القائمة — الشاشةُ المقارِنة التي
     * يفتح عليها السوبر أدمن. الفرع الذي لم يُنشأ له صفُّ إعدادات بعد يُعرض
     * بالقيم الافتراضية (البرنامج مُفعَّل بمعدلاته الأولى) لا بأصفار مضلّلة.
     *
     * الفروع محمّلة سلفاً لقائمة الاختيار، فالتقسيم يجري عليها في الذاكرة ولا
     * يُعاد استعلامها؛ ولا يُسأل عن الإعدادات والنقاط إلا لفروع الصفحة المعروضة.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function branchConfigs(Request $request, Collection $branches): array
    {
        $page = max(1, (int) $request->input('branchPage', 1));
        $total = $branches->count();
        $lastPage = max(1, (int) ceil($total / self::BRANCHES_PER_PAGE));
        $page = min($page, $lastPage);

        $pageBranches = $branches->slice(($page - 1) * self::BRANCHES_PER_PAGE, self::BRANCHES_PER_PAGE)->values();
        $ids = $pageBranches->pluck('id');

        $configs = LoyaltyConfig::query()->whereIn('branch_id', $ids)->get()->keyBy('branch_id');

        $points = Customer::query()
            ->whereIn('branch_id', $ids)
            ->selectRaw('branch_id, SUM(points_balance) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        $defaults = new LoyaltyConfig;

        return [
            'data' => $pageBranches->map(function (Branch $branch) use ($configs, $points, $defaults) {
                $config = $configs->get($branch->id) ?? $defaults;

                return [
                    'branchId' => $branch->id,
                    'branchName' => $branch->name,
                    'active' => (bool) ($config->is_active ?? true),
                    'earningRate' => (float) ($config->earning_rate ?? 1),
                    'redemptionRate' => (float) ($config->redemption_rate ?? 100),
                    'minRedemptionPoints' => (int) ($config->min_redemption_points ?? 500),
                    'expiryMonths' => $config->expiry_months,
                    'outstandingPoints' => (int) ($points[$branch->id] ?? 0),
                ];
            })->all(),
            'meta' => $this->pageMeta($page, $lastPage, $total, self::BRANCHES_PER_PAGE),
        ];
    }

    /**
     * عدد الفروع التي يعمل فيها برنامج الولاء. الفرع الذي لا صفَّ إعدادات له بعد
     * يعمل بالافتراضي (مُفعَّل)، فالمطروح هو المُوقَف صراحةً وحده.
     *
     * @param  Collection<int, Branch>  $branches
     */
    private function activeBranchCount(Collection $branches): int
    {
        $disabled = LoyaltyConfig::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->where('is_active', false)
            ->count();

        return max(0, $branches->count() - $disabled);
    }
}
