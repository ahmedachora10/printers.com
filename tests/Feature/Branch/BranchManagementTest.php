<?php

use App\Models\Branch;
use App\Models\City;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Branch Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->city = City::factory()->create();

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');
        $this->actingAs($this->superAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows super-admin to view branch list', function () {
        Branch::factory()->count(3)->create(['city_id' => $this->city->id]);

        $this->get(route('branches.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('branches/index'));
    });

    it('prevents non-super-admin from viewing branch list', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('branches.index'))->assertForbidden();
    });

    // ── CREATE ─────────────────────────────────────────────────────

    it('renders the create page with cities', function () {
        $this->get(route('branches.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('branches/create')->has('cities'));
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates a branch with valid data', function () {
        $this->post(route('branches.store'), [
            'name'              => 'فرع الرياض',
            'city_id'           => $this->city->id,
            'vat_rate_override' => 15.00,
            'is_active'         => true,
        ])->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', ['name' => 'فرع الرياض', 'city_id' => $this->city->id]);
    });

    it('fails to create a branch without required name', function () {
        $this->post(route('branches.store'), [
            'city_id'           => $this->city->id,
            'vat_rate_override' => 15.00,
        ])->assertSessionHasErrors(['name']);
    });

    it('fails to create a branch without city_id', function () {
        $this->post(route('branches.store'), [
            'name'              => 'فرع الرياض',
            'vat_rate_override' => 15.00,
        ])->assertSessionHasErrors(['city_id']);
    });

    it('fails to create a branch with name exceeding 255 characters', function () {
        $this->post(route('branches.store'), [
            'name'              => str_repeat('أ', 256),
            'city_id'           => $this->city->id,
            'vat_rate_override' => 15.00,
        ])->assertSessionHasErrors(['name']);
    });

    it('fails to create a branch with invalid vat rate', function () {
        $this->post(route('branches.store'), [
            'name'              => 'فرع الرياض',
            'city_id'           => $this->city->id,
            'vat_rate_override' => 150,
        ])->assertSessionHasErrors(['vat_rate_override']);
    });

    it('fails to create a branch with non-existent city', function () {
        $this->post(route('branches.store'), [
            'name'              => 'فرع الرياض',
            'city_id'           => 9999,
            'vat_rate_override' => 15.00,
        ])->assertSessionHasErrors(['city_id']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a branch name', function () {
        $branch = Branch::factory()->create(['city_id' => $this->city->id, 'name' => 'فرع الرياض']);

        $this->put(route('branches.update', $branch), ['name' => 'فرع جدة'])
            ->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'فرع جدة']);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles branch status from active to inactive', function () {
        $branch = Branch::factory()->create(['city_id' => $this->city->id, 'is_active' => true]);

        $this->patch(route('branches.toggle-status', $branch))
            ->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => false]);
    });

    it('toggles branch status from inactive to active', function () {
        $branch = Branch::factory()->create(['city_id' => $this->city->id, 'is_active' => false]);

        $this->patch(route('branches.toggle-status', $branch))
            ->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => true]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('soft-deletes a branch with no associated invoices', function () {
        $branch = Branch::factory()->create(['city_id' => $this->city->id]);

        $this->delete(route('branches.destroy', $branch))
            ->assertRedirect(route('branches.index'));

        $this->assertSoftDeleted('branches', ['id' => $branch->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents branch-admin from creating branches', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole('branch-admin');
        $this->actingAs($branchAdmin);

        $this->post(route('branches.store'), [
            'name'              => 'فرع تبوك',
            'city_id'           => $this->city->id,
            'vat_rate_override' => 15.00,
        ])->assertForbidden();
    });

    // ── OWNER ───────────────────────────────────────────────────────

    it('assigns a branch-admin as owner when creating a branch', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole('branch-admin');

        $this->post(route('branches.store'), [
            'name'              => 'فرع الرياض',
            'city_id'           => $this->city->id,
            'vat_rate_override' => 15.00,
            'owner_id'          => $branchAdmin->id,
        ])->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('branches', [
            'name'     => 'فرع الرياض',
            'owner_id' => $branchAdmin->id,
        ]);
    });

    it('rejects a non-branch-admin user as owner', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');

        $this->post(route('branches.store'), [
            'name'              => 'فرع جدة',
            'city_id'           => $this->city->id,
            'vat_rate_override' => 15.00,
            'owner_id'          => $employee->id,
        ])->assertSessionHasErrors(['owner_id']);
    });

    it('rejects an owner already owning another branch', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole('branch-admin');

        Branch::factory()->create([
            'city_id'  => $this->city->id,
            'owner_id' => $branchAdmin->id,
        ]);

        $this->post(route('branches.store'), [
            'name'              => 'فرع مكة',
            'city_id'           => $this->city->id,
            'vat_rate_override' => 15.00,
            'owner_id'          => $branchAdmin->id,
        ])->assertSessionHasErrors(['owner_id']);
    });

    it('prevents accountant from updating branches', function () {
        $accountant = User::factory()->create();
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $branch = Branch::factory()->create(['city_id' => $this->city->id]);

        $this->put(route('branches.update', $branch), ['name' => 'فرع معدل'])
            ->assertForbidden();
    });

    it('prevents employee from deleting branches', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $branch = Branch::factory()->create(['city_id' => $this->city->id]);

        $this->delete(route('branches.destroy', $branch))->assertForbidden();
    });
});
