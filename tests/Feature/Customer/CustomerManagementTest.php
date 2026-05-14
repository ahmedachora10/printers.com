<?php

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Customer Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
        $this->branchAdmin->addRole('branch-admin');
        $this->actingAs($this->branchAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows branch-admin to view customer list', function () {
        Customer::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('customers/index'));
    });

    it('allows employee to view customer list', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('customers.index'))->assertOk();
    });

    it('prevents agent from viewing customer list', function () {
        $agent = User::factory()->create(['branch_id' => $this->branch->id]);
        $agent->addRole('agent');
        $this->actingAs($agent);

        $this->get(route('customers.index'))->assertForbidden();
    });

    it('scopes customer list to own branch', function () {
        $otherBranch = Branch::factory()->create();
        Customer::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'عميل الفرع']);
        Customer::factory()->create(['branch_id' => $otherBranch->id, 'full_name' => 'عميل آخر']);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1));
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates an individual customer with valid data', function () {
        $this->post(route('customers.store'), [
            'full_name'     => 'أحمد محمد',
            'phone'         => '0512345678',
            'customer_type' => 'individual',
            'is_active'     => true,
        ])->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', [
            'full_name'  => 'أحمد محمد',
            'phone'      => '0512345678',
            'branch_id'  => $this->branch->id,
        ]);
    });

    it('creates a corporate customer with company_name', function () {
        $this->post(route('customers.store'), [
            'full_name'     => 'خالد العمري',
            'phone'         => '0523456789',
            'customer_type' => 'corporate',
            'company_name'  => 'شركة النجاح',
            'is_active'     => true,
        ])->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', [
            'phone'        => '0523456789',
            'company_name' => 'شركة النجاح',
        ]);
    });

    it('creates a customer with credit limit', function () {
        $this->post(route('customers.store'), [
            'full_name'     => 'سعد العتيبي',
            'phone'         => '0534567890',
            'customer_type' => 'individual',
            'credit_limit'  => 5000,
            'is_active'     => true,
        ])->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', [
            'phone'        => '0534567890',
            'credit_limit' => '5000.00',
        ]);
    });

    it('fails to create customer without required fields', function () {
        $this->post(route('customers.store'), [])
            ->assertSessionHasErrors(['full_name', 'phone', 'customer_type']);
    });

    it('fails to create corporate customer without company_name', function () {
        $this->post(route('customers.store'), [
            'full_name'     => 'اسم المؤسسة',
            'phone'         => '0545678901',
            'customer_type' => 'corporate',
        ])->assertSessionHasErrors(['company_name']);
    });

    it('fails to create duplicate phone in same branch', function () {
        Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'phone'     => '0512345678',
        ]);

        $this->post(route('customers.store'), [
            'full_name'     => 'عميل آخر',
            'phone'         => '0512345678',
            'customer_type' => 'individual',
        ])->assertSessionHasErrors(['phone']);
    });

    it('allows same phone in different branches', function () {
        $otherBranch = Branch::factory()->create();
        Customer::factory()->create([
            'branch_id' => $otherBranch->id,
            'phone'     => '0512345678',
        ]);

        $this->post(route('customers.store'), [
            'full_name'     => 'عميل جديد',
            'phone'         => '0512345678',
            'customer_type' => 'individual',
        ])->assertRedirect(route('customers.index'));
    });

    // ── SHOW ───────────────────────────────────────────────────────

    it('allows branch-admin to view customer profile', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('customers/show'));
    });

    it('prevents branch-admin from viewing another branch customer', function () {
        $otherBranch = Branch::factory()->create();
        $customer    = Customer::factory()->create(['branch_id' => $otherBranch->id]);

        $this->get(route('customers.show', $customer))->assertForbidden();
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a customer', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->put(route('customers.update', $customer), [
            'full_name'     => 'اسم محدّث',
            'phone'         => '0598765432',
            'customer_type' => 'individual',
            'is_active'     => true,
        ])->assertRedirect(route('customers.show', $customer));

        $this->assertDatabaseHas('customers', [
            'id'        => $customer->id,
            'full_name' => 'اسم محدّث',
        ]);
    });

    it('prevents employee from updating customer', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole('employee');
        $this->actingAs($employee);

        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->put(route('customers.update', $customer), [
            'full_name'     => 'هاكر',
            'phone'         => '0598765432',
            'customer_type' => 'individual',
        ])->assertForbidden();
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles customer active status', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

        $this->patch(route('customers.toggle-status', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'is_active' => false]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('soft-deletes a customer with no invoices', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    });

    it('prevents employee from deleting a customer', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole('employee');
        $this->actingAs($employee);

        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->delete(route('customers.destroy', $customer))->assertForbidden();
    });

    it('prevents branch-admin from deleting another branch customer', function () {
        $otherBranch = Branch::factory()->create();
        $customer    = Customer::factory()->create(['branch_id' => $otherBranch->id]);

        $this->delete(route('customers.destroy', $customer))->assertForbidden();
    });

    // ── MERGE ──────────────────────────────────────────────────────

    it('merges secondary customer into primary and soft-deletes secondary', function () {
        $primary   = Customer::factory()->create([
            'branch_id'     => $this->branch->id,
            'points_balance' => 100,
        ]);
        $secondary = Customer::factory()->create([
            'branch_id'     => $this->branch->id,
            'points_balance' => 50,
        ]);

        $this->post(route('customers.merge', $primary), [
            'secondary_customer_id' => $secondary->id,
        ])->assertRedirect(route('customers.show', $primary));

        $primary->refresh();
        expect($primary->points_balance)->toBe(150);
        $this->assertSoftDeleted('customers', ['id' => $secondary->id]);
    });

    it('fails to merge a customer with itself', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->post(route('customers.merge', $customer), [
            'secondary_customer_id' => $customer->id,
        ])->assertSessionHasErrors(['secondary_customer_id']);
    });

    it('prevents employee from merging customers', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole('employee');
        $this->actingAs($employee);

        $primary   = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $secondary = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->post(route('customers.merge', $primary), [
            'secondary_customer_id' => $secondary->id,
        ])->assertForbidden();
    });

    // ── OUTSTANDING BALANCE REPORT ─────────────────────────────────

    it('renders outstanding balance report page', function () {
        $this->get(route('customers.outstanding-balance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('customers/outstanding-balance'));
    });

    // ── FACTORY STATES ─────────────────────────────────────────────

    it('creates bronze tier customer via factory', function () {
        $customer = Customer::factory()->bronze()->create(['branch_id' => $this->branch->id]);

        expect($customer->tier)->toBe(CustomerTierEnum::Bronze);
        expect((float) $customer->cumulative_spend)->toBeGreaterThanOrEqual(500);
    });

    it('creates gold tier customer via factory', function () {
        $customer = Customer::factory()->gold()->create(['branch_id' => $this->branch->id]);

        expect($customer->tier)->toBe(CustomerTierEnum::Gold);
    });

    it('creates corporate customer via factory', function () {
        $customer = Customer::factory()->corporate()->create(['branch_id' => $this->branch->id]);

        expect($customer->customer_type)->toBe(CustomerTypeEnum::Corporate);
        expect($customer->company_name)->not->toBeNull();
    });
});
