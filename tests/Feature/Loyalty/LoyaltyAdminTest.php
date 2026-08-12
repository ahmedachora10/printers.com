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

    it('stores an expiry window', function () {
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload(['expiry_months' => 18]))
            ->assertRedirect();

        expect(LoyaltyConfig::where('branch_id', $this->branch->id)->first()->expiry_months)->toBe(18);
    });

    // الحقل يصل نصاً فارغاً من النموذج، ومعناه «بلا انتهاء صلاحية» لا صفراً.
    it('reads a blank expiry window as no expiry at all', function () {
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload(['expiry_months' => 18]));
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload(['expiry_months' => '']))
            ->assertRedirect();

        expect(LoyaltyConfig::where('branch_id', $this->branch->id)->first()->expiry_months)->toBeNull();
    });

    it('rejects an expiry window below a month', function () {
        put(route('app-settings.update-loyalty'), loyaltyConfigPayload(['expiry_months' => 0]))
            ->assertSessionHasErrors('expiry_months');
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

    it('serves the branch admin their own config', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 2]);

        get(route('loyalty.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isSuperAdmin', false)
                ->where('config.earningRate', 2)
                ->has('branches', 0)
                ->has('branchConfigs', 0));
    });
});

/**
 * الإعدادات لكل فرع، فلا بطاقة معدّلٍ واحدة تصلح للشبكة: السوبر أدمن يرى
 * المجاميع وجدول مقارنة، ويختار فرعاً فيرى شاشته كاملة.
 */
describe('Loyalty overview for the super admin', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);
        actingAs($this->superAdmin);

        $this->first = Branch::factory()->create(['name' => 'الفرع الأول']);
        $this->second = Branch::factory()->create(['name' => 'الفرع الثاني']);

        LoyaltyConfig::factory()->create(['branch_id' => $this->first->id, 'earning_rate' => 1, 'expiry_months' => 12]);
        LoyaltyConfig::factory()->inactive()->create(['branch_id' => $this->second->id, 'earning_rate' => 3]);

        Customer::factory()->create(['branch_id' => $this->first->id, 'points_balance' => 100]);
        Customer::factory()->create(['branch_id' => $this->second->id, 'points_balance' => 250]);
    });

    it('aggregates every branch when none is picked', function () {
        get(route('loyalty.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isSuperAdmin', true)
                ->where('outstandingPoints', 350)
                ->where('customerCount', 2)
                // لا إعدادات واحدة تُعرض على مستوى الشبكة
                ->where('config', null)
                ->has('branchConfigs', 2)
                ->has('branches', 2));
    });

    it('describes each branch in the comparison table', function () {
        get(route('loyalty.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $rows = collect($page->toArray()['props']['branchConfigs'])->keyBy('branchName');

                expect($rows['الفرع الأول']['active'])->toBeTrue()
                    ->and($rows['الفرع الأول']['expiryMonths'])->toBe(12)
                    ->and($rows['الفرع الأول']['outstandingPoints'])->toBe(100)
                    ->and($rows['الفرع الثاني']['active'])->toBeFalse()
                    ->and($rows['الفرع الثاني']['earningRate'])->toEqual(3)
                    ->and($rows['الفرع الثاني']['expiryMonths'])->toBeNull();
            });
    });

    it('narrows to a single branch and shows its config', function () {
        get(route('loyalty.index', ['branch' => $this->second->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('outstandingPoints', 250)
                ->where('config.active', false)
                ->where('config.earningRate', 3)
                ->where('filters.branch', (string) $this->second->id)
                // الجدول المقارن يسقط حين يُختار فرع بعينه
                ->has('branchConfigs', 0));
    });

    it('scopes the transaction log to the picked branch', function () {
        $mine = Customer::factory()->create(['branch_id' => $this->first->id, 'points_balance' => 10]);
        $theirs = Customer::factory()->create(['branch_id' => $this->second->id, 'points_balance' => 10]);

        foreach ([$mine, $theirs] as $customer) {
            LoyaltyTransaction::factory()->create([
                'customer_id' => $customer->id,
                'type' => LoyaltyTransactionTypeEnum::Earn,
                'points' => 10,
                'balance_after' => 10,
            ]);
        }

        get(route('loyalty.index'))
            ->assertInertia(fn ($page) => $page->has('transactions', 2));

        get(route('loyalty.index', ['branch' => $this->first->id]))
            ->assertInertia(fn ($page) => $page->has('transactions', 1));
    });

    it('falls back to defaults for a branch with no config row yet', function () {
        $fresh = Branch::factory()->create(['name' => 'فرع بلا إعدادات']);

        get(route('loyalty.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $row = collect($page->toArray()['props']['branchConfigs'])
                    ->firstWhere('branchName', 'فرع بلا إعدادات');

                // لا أصفار مضلّلة: تُعرض القيم الافتراضية التي سيعمل بها الفرع
                expect($row['active'])->toBeTrue()
                    ->and($row['earningRate'])->toEqual(1)
                    ->and($row['redemptionRate'])->toEqual(100);
            });
    });
});
