<?php

namespace App\Actions\Customer;

use App\Actions\Loyalty\ResolveAvailablePointsAction;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use Illuminate\Support\Collection;

class SearchPosCustomersAction
{
    public function __construct(
        private readonly ResolveAvailablePointsAction $availablePoints,
    ) {}

    /**
     * Branch-scoped customer lookup for the POS pickers, capped so a large customer
     * base never ships wholesale to the browser. Rows are shaped with loyalty
     * eligibility via Customer::toPosArray().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(?int $branchId, string $term, int $limit = 30): Collection
    {
        $loyalty = $branchId ? LoyaltyConfig::forBranch($branchId) : null;
        $loyaltyActive = (bool) ($loyalty?->is_active);

        $term = trim($term);

        $customers = Customer::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                $q->where('full_name', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', $term.'%');
            }))
            ->orderBy('full_name')
            ->limit($limit)
            ->get(['id', 'full_name', 'phone', 'tax_number', 'agent_id', 'customer_type', 'points_balance', 'tier']);

        // النقاط المحجوزة لكل عملاء القائمة باستعلامين، لا باستعلامين لكل عميل.
        $reserved = $this->availablePoints->reservedFor($customers->pluck('id')->all());

        return $customers->map(
            fn (Customer $customer) => $customer->toPosArray($loyalty, $loyaltyActive, $reserved[$customer->id] ?? 0),
        );
    }
}
