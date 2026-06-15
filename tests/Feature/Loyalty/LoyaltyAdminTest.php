<?php

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

uses(RefreshDatabase::class);

function loyaltyConfigPayload(array $overrides = []): array
{
    return array_merge([
        'is_active' => true,
        'earning_rate' => 2,
        'redemption_rate' => 50,
        'min_redemption_points' => 300,
        'bronze_threshold' => 400,
        'silver_threshold' => 1500,
        'gold_threshold' => 4000,
        'bronze_discount_pct' => 3,
        'silver_discount_pct' => 6,
        'gold_discount_pct' => 10,
    ], $overrides);
}

describe('Loyalty config (app-settings)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        actingAs($this->admin);
    });

    it('exposes the loyalty config on the settings page', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1]);

        get(route('app-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app-settings/index')
                ->where('canConfigureLoyalty', true)
                ->where('loyaltyConfig.earningRate', 1));
    });

    it('updates the branch loyalty config', function () {
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $config = LoyaltyConfig::where('branch_id', $this->branch->id)->firstOrFail();
        expect((float) $config->earning_rate)->toBe(2.0)
            ->and((int) $config->min_redemption_points)->toBe(300)
            ->and((float) $config->gold_discount_pct)->toBe(10.0)
            ->and((bool) $config->is_active)->toBeTrue();
    });

    it('can deactivate the program', function () {
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload(['is_active' => false]));

        expect((bool) LoyaltyConfig::where('branch_id', $this->branch->id)->first()->is_active)->toBeFalse();
    });

    it('rejects a zero redemption rate', function () {
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload(['redemption_rate' => 0]))
            ->assertSessionHasErrors('redemption_rate');
    });

    it('rejects tier thresholds that are out of order', function () {
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload([
            'silver_threshold' => 100,
            'bronze_threshold' => 500,
        ]))->assertSessionHasErrors('silver_threshold');
    });

    it('forbids an employee from updating the loyalty config', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        actingAs($employee);

        put(route('app-settings.update-loyalty'), loyaltyConfigPayload())->assertForbidden();
    });
});

describe('Loyalty overview', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        actingAs($this->admin);
    });

    it('shows tier distribution and the transaction log', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'points_balance' => 120]);
        LoyaltyTransaction::factory()->create([
            'customer_id' => $customer->id,
            'type' => LoyaltyTransactionTypeEnum::Earn,
            'points' => 120,
            'balance_after' => 120,
        ]);

        get(route('loyalty.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('loyalty/index')
                ->where('outstandingPoints', 120)
                ->has('tierDistribution', 4)
                ->has('transactions', 1));
    });

    it('forbids an employee from viewing the overview', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        actingAs($employee);

        get(route('loyalty.index'))->assertForbidden();
    });
});
