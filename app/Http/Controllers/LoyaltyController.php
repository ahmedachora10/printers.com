<?php

namespace App\Http\Controllers;

use App\Enums\CustomerTierEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoyaltyController extends Controller
{
    /**
     * لوحة برنامج الولاء.
     *
     * الإعدادات تُحفظ لكل فرع على حدة، فلا معنى لبطاقة «معدل الاكتساب» واحدة على
     * مستوى الشبكة: السوبر أدمن يرى الأرقام مجموعةً عبر الفروع مع جدولٍ يقارن
     * إعدادات كل فرع، وله أن يختار فرعاً بعينه فتصير الشاشة كما يراها مديره. ومن
     * سواه مربوطٌ بفرعه كما كان.
     */
    public function index(Request $request): Response
    {
        $actor = Auth::user();
        $isSuper = $actor->roleName?->isSuperAdmin() ?? false;

        $branchId = $isSuper
            ? ($request->filled('branch') ? (int) $request->input('branch') : null)
            : $actor->branchId;

        $config = $branchId !== null ? LoyaltyConfig::forBranch($branchId) : null;

        $customers = Customer::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            // السوبر أدمن بلا فرع محدَّد يرى الشبكة كلها؛ ومن سواه بلا فرع (حالة
            // لا ينبغي أن تقع) لا يرى شيئاً بدل أن يرى كل شيء.
            ->when(! $isSuper && $branchId === null, fn ($q) => $q->whereRaw('1 = 0'));

        $tierCounts = (clone $customers)
            ->selectRaw('tier, COUNT(*) as total')
            ->groupBy('tier')
            ->pluck('total', 'tier');

        $tierDistribution = collect(CustomerTierEnum::cases())->map(fn (CustomerTierEnum $tier) => [
            'tier' => $tier->value,
            'label' => $tier->label(),
            'count' => (int) ($tierCounts[$tier->value] ?? 0),
        ])->values();

        $transactions = LoyaltyTransaction::query()
            ->with('customer:id,full_name,phone,branch_id')
            ->whereHas('customer', function ($q) use ($branchId, $isSuper) {
                $q->when($branchId, fn ($c) => $c->where('branch_id', $branchId))
                    ->when(! $isSuper && $branchId === null, fn ($c) => $c->whereRaw('1 = 0'));
            })
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (LoyaltyTransaction $tx) => [
                'id' => $tx->id,
                'customerName' => $tx->customer?->full_name,
                'customerPhone' => $tx->customer?->phone,
                'type' => $tx->type->value,
                'typeLabel' => $tx->type->label(),
                'points' => $tx->points,
                'balanceAfter' => $tx->balance_after,
                'createdAt' => $tx->created_at->format('Y-m-d H:i'),
            ]);

        return Inertia::render('loyalty/index', [
            // بلا فرع محدَّد لا توجد إعدادات واحدة تُعرض — الجدول أدناه يقوم مقامها.
            'config' => $config ? [
                'active' => (bool) $config->is_active,
                'earningRate' => (float) $config->earning_rate,
                'redemptionRate' => (float) $config->redemption_rate,
                'minRedemptionPoints' => (int) $config->min_redemption_points,
                'expiryMonths' => $config->expiry_months,
            ] : null,
            'outstandingPoints' => (int) (clone $customers)->sum('points_balance'),
            'customerCount' => (int) (clone $customers)->count(),
            'tierDistribution' => $tierDistribution,
            'transactions' => $transactions,
            'branchConfigs' => $isSuper && $branchId === null ? $this->branchConfigs() : [],
            'branches' => $isSuper
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $isSuper,
            'filters' => ['branch' => $branchId !== null ? (string) $branchId : null],
        ]);
    }

    /**
     * إعدادات الولاء لكل فرع نشط، ومعها نقاطه القائمة — الشاشةُ المقارِنة التي
     * يفتح عليها السوبر أدمن. الفرع الذي لم يُنشأ له صفُّ إعدادات بعد يُعرض
     * بالقيم الافتراضية (البرنامج مُفعَّل بمعدلاته الأولى) لا بأصفار مضلّلة.
     *
     * @return list<array<string, mixed>>
     */
    private function branchConfigs(): array
    {
        $branches = Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $configs = LoyaltyConfig::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->get()
            ->keyBy('branch_id');

        $points = Customer::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->selectRaw('branch_id, SUM(points_balance) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        $defaults = new LoyaltyConfig;

        return $branches->map(function (Branch $branch) use ($configs, $points, $defaults) {
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
        })->values()->all();
    }
}
