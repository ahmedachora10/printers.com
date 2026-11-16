<?php

use App\Models\Branch;
use App\Models\User;
use App\Notifications\BranchSubscriptionExpiringNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// تاسك 228: اشتراك الفرع — العدّاد، التنبيه، المهلة، القراءة فقط، والتجميد.

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-11-16 10:00:00');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole('super-admin');

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole('branch-admin');
    $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);

    $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->employee->addRole('employee');
});

function setSubscription(Branch $branch, ?string $ends, ?string $grace = null): void
{
    $branch->update([
        'subscription_starts_at' => $ends ? '2025-11-16' : null,
        'subscription_ends_at' => $ends,
        'subscription_grace_until' => $grace,
    ]);
}

it('lets the super-admin save the subscription and logs it', function () {
    $this->actingAs($this->superAdmin)->put(route('branches.update', $this->branch), [
        'subscription_starts_at' => '2026-01-01',
        'subscription_ends_at' => '2026-12-31',
        'subscription_grace_until' => '2027-01-15',
    ])->assertSessionHasNoErrors();

    $branch = $this->branch->fresh();
    expect($branch->subscription_ends_at->toDateString())->toBe('2026-12-31')
        ->and($branch->subscription_grace_until->toDateString())->toBe('2027-01-15');

    $this->assertDatabaseHas('activity_log', ['subject_type' => Branch::class, 'subject_id' => $branch->id, 'log_name' => 'branches']);
});

it('rejects an end before the start and a grace before the end', function () {
    $this->actingAs($this->superAdmin)->put(route('branches.update', $this->branch), [
        'subscription_starts_at' => '2026-12-31',
        'subscription_ends_at' => '2026-01-01',
        'subscription_grace_until' => '2025-01-01',
    ])->assertSessionHasErrors(['subscription_ends_at', 'subscription_grace_until']);
});

it('ignores subscription fields sent from the branch-admin profile screen', function () {
    setSubscription($this->branch, '2026-12-01');

    $this->actingAs($this->branchAdmin)->post(route('app-settings.update-branch-profile'), [
        '_method' => 'put',
        'name' => $this->branch->name,
        'city_id' => $this->branch->city_id,
        'vat_rate_override' => 15,
        'subscription_ends_at' => '2030-01-01',
    ]);

    expect($this->branch->fresh()->subscription_ends_at->toDateString())->toBe('2026-12-01');
});

it('derives the state from the end date and grace period', function (?string $ends, ?string $grace, ?string $state, ?int $days) {
    setSubscription($this->branch, $ends, $grace);
    $branch = $this->branch->fresh();

    expect($branch->subscriptionState())->toBe($state)
        ->and($branch->subscriptionDaysLeft())->toBe($days);
})->with([
    'no subscription' => [null, null, null, null],
    'far away' => ['2027-06-01', null, 'active', 197],
    'within 30 days' => ['2026-12-16', null, 'expiring', 30],
    'last day' => ['2026-11-16', null, 'expiring', 0],
    'in grace' => ['2026-11-10', '2026-11-20', 'grace', -6],
    'grace last day' => ['2026-11-10', '2026-11-16', 'grace', -6],
    'expired' => ['2026-11-10', null, 'expired', -6],
    'grace over' => ['2026-11-01', '2026-11-15', 'expired', -15],
]);

describe('read-only after expiry', function () {
    it('blocks writes and allows reads for an expired branch', function () {
        setSubscription($this->branch, '2026-11-01');

        $this->actingAs($this->branchAdmin)
            ->post(route('product-categories.store'), ['name' => 'أحبار'])
            ->assertSessionHasErrors('subscription');

        $this->assertDatabaseMissing('product_categories', ['name' => 'أحبار']);
        $this->get(route('dashboard'))->assertOk();
    });

    it('still allows logging out and reading notifications', function () {
        setSubscription($this->branch, '2026-11-01');

        $this->actingAs($this->employee)->patch(route('notifications.read-all'))->assertSessionHasNoErrors();
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    });

    it('allows writes during the grace period', function () {
        setSubscription($this->branch, '2026-11-01', '2026-11-30');

        $this->actingAs($this->branchAdmin)
            ->post(route('product-categories.store'), ['name' => 'أحبار'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_categories', ['name' => 'أحبار']);
    });

    it('lets an impersonating super-admin write', function () {
        setSubscription($this->branch, '2026-11-01');

        $this->actingAs($this->branchAdmin)
            ->withSession(['impersonator_id' => $this->superAdmin->id])
            ->post(route('product-categories.store'), ['name' => 'أحبار'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_categories', ['name' => 'أحبار']);
    });
});

describe('freezing a branch', function () {
    it('blocks login for users of a frozen branch', function () {
        $this->branch->update(['is_active' => false]);

        $this->post('/login', ['username' => $this->employee->username, 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'هذا الفرع مجمَّد، راجع الإدارة']);
        $this->assertGuest();

        $this->post('/login', ['username' => $this->branchAdmin->username, 'password' => 'password']);
        $this->assertGuest();
    });

    it('logs out a user whose branch is frozen mid-session', function () {
        $this->actingAs($this->employee)->get(route('dashboard'))->assertOk();

        Branch::query()->whereKey($this->branch->id)->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('does not lock out an agent whose home branch is frozen', function () {
        $agent = User::factory()->create(['branch_id' => $this->branch->id]);
        $agent->addRole('agent');
        $this->branch->update(['is_active' => false]);

        expect($agent->fresh()->accessDeniedReason())->toBeNull();
    });
});

describe('expiry alerts', function () {
    it('notifies the branch admin at 30, 15 and 7 days only, once each', function () {
        Notification::fake();
        setSubscription($this->branch, '2026-12-16'); // 30 days from today

        $this->artisan('branches:notify-subscription-expiry')->assertSuccessful();
        Notification::assertSentToTimes($this->branchAdmin, BranchSubscriptionExpiringNotification::class, 1);
        Notification::assertNotSentTo($this->employee, BranchSubscriptionExpiringNotification::class);

        $this->travelTo('2026-11-17 08:00:00'); // 29 days — no threshold
        $this->artisan('branches:notify-subscription-expiry');
        Notification::assertSentToTimes($this->branchAdmin, BranchSubscriptionExpiringNotification::class, 1);

        $this->travelTo('2026-12-01 08:00:00'); // 15 days
        $this->artisan('branches:notify-subscription-expiry');
        $this->travelTo('2026-12-09 08:00:00'); // 7 days
        $this->artisan('branches:notify-subscription-expiry');
        Notification::assertSentToTimes($this->branchAdmin, BranchSubscriptionExpiringNotification::class, 3);
    });

    it('does not repeat an alert when run twice on the same day', function () {
        setSubscription($this->branch, '2026-11-23'); // 7 days

        $this->artisan('branches:notify-subscription-expiry');
        $this->artisan('branches:notify-subscription-expiry');

        expect($this->branchAdmin->notifications()->count())->toBe(1);
    });
});

it('shares the subscription with branch users and not with the super-admin', function () {
    setSubscription($this->branch, '2026-11-26');

    $this->actingAs($this->branchAdmin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.branch.subscription.daysLeft', 10)
            ->where('auth.branch.subscription.state', 'expiring'));

    $this->actingAs($this->employee)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.branch.subscription.state', 'expiring'));

    $this->actingAs($this->superAdmin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.branch', null));
});
