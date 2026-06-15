<?php

namespace App\Http\Controllers;

use App\Enums\CustomerTierEnum;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoyaltyController extends Controller
{
    public function index(): Response
    {
        $branchId = Auth::user()->branchId;
        $config = $branchId ? LoyaltyConfig::forBranch($branchId) : null;

        $customers = Customer::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        // Customer counts per tier for the branch.
        $tierCounts = (clone $customers)
            ->selectRaw('tier, COUNT(*) as total')
            ->groupBy('tier')
            ->pluck('total', 'tier');

        $tierDistribution = collect(CustomerTierEnum::cases())->map(fn (CustomerTierEnum $tier) => [
            'tier' => $tier->value,
            'label' => $tier->label(),
            'count' => (int) ($tierCounts[$tier->value] ?? 0),
        ])->values();

        $outstandingPoints = (int) (clone $customers)->sum('points_balance');

        $transactions = LoyaltyTransaction::query()
            ->with('customer:id,full_name,phone')
            ->when($branchId, fn ($q) => $q->whereHas(
                'customer',
                fn ($c) => $c->where('branch_id', $branchId),
            ))
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
            'active' => (bool) ($config?->is_active),
            'earningRate' => (float) ($config->earning_rate ?? 0),
            'redemptionRate' => (float) ($config->redemption_rate ?? 0),
            'minRedemptionPoints' => (int) ($config->min_redemption_points ?? 0),
            'outstandingPoints' => $outstandingPoints,
            'tierDistribution' => $tierDistribution,
            'transactions' => $transactions,
        ]);
    }
}
