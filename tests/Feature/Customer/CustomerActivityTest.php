<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function activityServiceInvoice(Customer $customer, User $user, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-TST-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $customer->branch_id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'subtotal' => 200,
        'vat_pct' => 15,
        'vat_amount' => 30,
        'total_amount' => 230,
        'employee_commission' => 20,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));
}

function activityProductInvoice(Customer $customer, User $user, array $overrides = []): ProductInvoice
{
    return ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-TST-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $customer->branch_id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));
}

describe('Customer Activity (M23)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
        $this->branchAdmin->addRole('branch-admin');

        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin);
    });

    // ── ACCESS ─────────────────────────────────────────────────────

    it('renders the activity page for a branch admin', function () {
        // Timeline and analytics are deferred props: absent on first load,
        // then fetched via Inertia's follow-up partial reload.
        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('customers/activity')
                ->has('customer')
                ->missing('timeline')
                ->missing('analytics')
                ->loadDeferredProps(fn ($page) => $page
                    ->has('timeline')
                    ->has('analytics.monthlySpend', 12)
                    ->has('analytics.kpis')
                    ->has('analytics.topServices')
                    ->has('analytics.topProducts')));
    });

    it('allows an employee of the same branch', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole('employee');

        $this->actingAs($employee)
            ->get(route('customers.activity', $this->customer))
            ->assertOk();
    });

    it('forbids users from another branch', function () {
        $otherAdmin = User::factory()->create();
        $otherBranch = Branch::factory()->create(['owner_id' => $otherAdmin->id]);
        $otherAdmin->update(['branch_id' => $otherBranch->id]);
        $otherAdmin->addRole('branch-admin');

        $this->actingAs($otherAdmin)
            ->get(route('customers.activity', $this->customer))
            ->assertForbidden();
    });

    it('forbids agents via route middleware', function () {
        $agent = User::factory()->create(['branch_id' => $this->branch->id]);
        $agent->addRole('agent');

        $this->actingAs($agent)
            ->get(route('customers.activity', $this->customer))
            ->assertForbidden();
    });

    // ── AUDIT LOG ──────────────────────────────────────────────────

    it('logs customer creation and shows it in the timeline', function () {
        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->loadDeferredProps('timeline', fn ($page) => $page
                ->where('timeline.0.kind', 'audit')
                ->where('timeline.0.event', 'created')));
    });

    it('logs only dirty fields on update', function () {
        $this->patch(route('customers.update', $this->customer), [
            'full_name' => 'اسم محدث',
            'phone' => $this->customer->phone,
            'customer_type' => $this->customer->customer_type->value,
            'is_active' => true,
        ]);

        $updated = DB::table('activity_log')
            ->where('subject_type', Customer::class)
            ->where('subject_id', $this->customer->id)
            ->where('event', 'updated')
            ->first();

        expect($updated)->not->toBeNull();

        $changed = array_keys(json_decode($updated->properties, true)['attributes']);
        expect($changed)->toContain('full_name')->not->toContain('phone');
    });

    // ── TIMELINE ───────────────────────────────────────────────────

    it('includes invoices, loyalty movements and refunds in the timeline', function () {
        $invoice = activityServiceInvoice($this->customer, $this->branchAdmin);
        activityProductInvoice($this->customer, $this->branchAdmin);

        LoyaltyTransaction::factory()->create([
            'customer_id' => $this->customer->id,
            'points' => 25,
            'balance_after' => 25,
        ]);

        Refund::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->getMorphClass(),
            'amount' => 50,
            'reason' => 'خطأ في الطلب',
        ]);

        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->loadDeferredProps('timeline', function ($page) {
                $kinds = collect($page->toArray()['props']['timeline'])->pluck('kind');

                expect($kinds)->toContain('invoice')
                    ->toContain('loyalty')
                    ->toContain('refund')
                    ->toContain('audit');
            }));
    });

    it('does not leak another customer\'s events into the timeline', function () {
        $other = Customer::factory()->create(['branch_id' => $this->branch->id]);
        activityServiceInvoice($other, $this->branchAdmin);

        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->loadDeferredProps('timeline', function ($page) {
                $kinds = collect($page->toArray()['props']['timeline'])->pluck('kind');
                expect($kinds)->not->toContain('invoice');
            }));
    });

    it('sorts the timeline newest first', function () {
        activityServiceInvoice($this->customer, $this->branchAdmin, ['created_at' => now()->subDays(3)]);
        activityProductInvoice($this->customer, $this->branchAdmin, ['created_at' => now()->subDay()]);

        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->loadDeferredProps('timeline', function ($page) {
                $times = collect($page->toArray()['props']['timeline'])->pluck('occurredAt');
                expect($times->toArray())->toEqual($times->sortDesc()->values()->toArray());
            }));
    });

    // ── ANALYTICS ──────────────────────────────────────────────────

    it('computes realized KPIs from paid invoices only', function () {
        activityServiceInvoice($this->customer, $this->branchAdmin); // 230 paid
        activityProductInvoice($this->customer, $this->branchAdmin); // 115 paid
        activityServiceInvoice($this->customer, $this->branchAdmin, [
            'status' => 'due',
            'paid_at' => null,
            'total_amount' => 999,
        ]);

        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->loadDeferredProps('analytics', fn ($page) => $page
                ->where('analytics.kpis.paidInvoiceCount', 2)
                ->where('analytics.kpis.lifetimeSpend', 345)
                ->where('analytics.kpis.avgInvoiceValue', 172.5)
                ->where('analytics.kpis.daysSinceLastPurchase', 0)));
    });

    it('buckets paid spend into the current month', function () {
        activityServiceInvoice($this->customer, $this->branchAdmin);

        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->loadDeferredProps('analytics', fn ($page) => $page
                ->where('analytics.monthlySpend.11.month', now()->format('Y-m'))
                ->where('analytics.monthlySpend.11.service', 230)
                ->where('analytics.monthlySpend.11.product', 0)));
    });

    it('ranks top purchased services by value and excludes cancelled invoices', function () {
        $invoice = activityServiceInvoice($this->customer, $this->branchAdmin);
        $cancelled = activityServiceInvoice($this->customer, $this->branchAdmin, ['status' => 'cancelled', 'paid_at' => null]);

        DB::table('service_invoice_lines')->insert([
            [
                'invoice_id' => $invoice->id,
                'service_name' => 'طباعة ملونة',
                'qty' => 2,
                'unit_price' => 50,
                'discount_pct' => 0,
                'subtotal' => 100,
                'commission_pct' => 0,
                'commission_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $invoice->id,
                'service_name' => 'تصوير مستندات',
                'qty' => 10,
                'unit_price' => 20,
                'discount_pct' => 0,
                'subtotal' => 200,
                'commission_pct' => 0,
                'commission_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $cancelled->id,
                'service_name' => 'خدمة ملغاة',
                'qty' => 1,
                'unit_price' => 500,
                'discount_pct' => 0,
                'subtotal' => 500,
                'commission_pct' => 0,
                'commission_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->get(route('customers.activity', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->loadDeferredProps('analytics', fn ($page) => $page
                ->has('analytics.topServices', 2)
                ->where('analytics.topServices.0.name', 'تصوير مستندات')
                ->where('analytics.topServices.0.total', 200)
                ->where('analytics.topServices.1.name', 'طباعة ملونة')));
    });
});
