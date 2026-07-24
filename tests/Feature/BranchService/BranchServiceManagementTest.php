<?php

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\City;
use App\Models\ServiceTemplate;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('BranchService Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->city     = City::factory()->create();
        $this->template = ServiceTemplate::factory()->create();

        // Branch admin must exist before branch so owner_id can be set
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole('branch-admin');

        $this->branch = Branch::factory()->create([
            'city_id'  => $this->city->id,
            'owner_id' => $this->branchAdmin->id,
        ]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');

        $this->actingAs($this->branchAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('branch-admin can view their branch services index', function () {
        $this->get(route('branch-services.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('branch-services/index'));
    });

    it('prevents employee from viewing branch services index', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('branch-services.index'))->assertForbidden();
    });

    // ── STORE (ATTACH) ─────────────────────────────────────────────

    it('super-admin is forbidden from branch-services store (role middleware)', function () {
        $this->actingAs($this->superAdmin);

        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 10.00,
            'max_discount_pct'    => 5.00,
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertStatus(302);
    });

    it('branch-admin can attach their own branch to a service template', function () {
        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 8.00,
            'max_discount_pct'    => 3.00,
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertRedirect(route('branch-services.index'));

        $this->assertDatabaseHas('branch_services', [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
        ]);
    });

    it('stores square-meter pricing settings on a branch service', function () {
        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 8.00,
            'max_discount_pct'    => 3.00,
            'pricing_type'        => 'sqm',
            'price_per_sqm'       => 120.50,
            'agent_commission_per_sqm' => 4.25,
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertRedirect(route('branch-services.index'));

        $this->assertDatabaseHas('branch_services', [
            'service_template_id' => $this->template->id,
            'pricing_type'        => 'sqm',
            'price_per_sqm'       => 120.50,
            'agent_commission_per_sqm' => 4.25,
        ]);
    });

    it('requires the price per sqm when the pricing type is sqm', function () {
        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 8.00,
            'max_discount_pct'    => 3.00,
            'pricing_type'        => 'sqm',
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertSessionHasErrors('price_per_sqm');
    });

    it('prevents employee from attaching a branch service', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 10.00,
            'max_discount_pct'    => 5.00,
        ])->assertForbidden();
    });

    it('prevents duplicate (branch, template) pairs', function () {
        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        // acting as branchAdmin (set in beforeEach)
        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 10.00,
            'max_discount_pct'    => 5.00,
        ])->assertSessionHasErrors(['branch_id']);
    });

    it('validates commission pct does not exceed 100', function () {
        // acting as branchAdmin (set in beforeEach)
        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 150.00,
            'max_discount_pct'    => 5.00,
        ])->assertSessionHasErrors(['base_commission_pct']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('super-admin is forbidden from branch-services update (role middleware)', function () {
        $this->actingAs($this->superAdmin);

        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->put(route('branch-services.update', $branchService), [
            'base_commission_pct' => 15.00,
            'max_discount_pct'    => 8.00,
            'is_tahazir'          => true,
            'is_active'           => true,
        ])->assertStatus(302);
    });

    it('branch-admin can update their own branch service', function () {
        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->put(route('branch-services.update', $branchService), [
            'base_commission_pct' => 15.00,
            'max_discount_pct'    => 8.00,
            'is_tahazir'          => true,
            'is_active'           => true,
        ])->assertRedirect(route('branch-services.index'));

        $this->assertDatabaseHas('branch_services', [
            'id'                  => $branchService->id,
            'base_commission_pct' => 15.00,
            'is_tahazir'          => true,
        ]);
    });

    it('branch-admin cannot update another branch service', function () {
        $otherCity   = City::factory()->create();
        $otherBranch = Branch::factory()->create(['city_id' => $otherCity->id]);

        $this->template->branches()->attach($otherBranch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $otherBranch->id)
            ->first();

        $this->put(route('branch-services.update', $branchService), [
            'base_commission_pct' => 20.00,
            'max_discount_pct'    => 10.00,
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertForbidden();
    });

    // ── DESTROY (DETACH) ───────────────────────────────────────────

    it('super-admin is forbidden from branch-services destroy (role middleware)', function () {
        $this->actingAs($this->superAdmin);

        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->delete(route('branch-services.destroy', $branchService))
            ->assertStatus(302);
    });

    it('branch-admin can detach their own branch service', function () {
        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->delete(route('branch-services.destroy', $branchService))
            ->assertRedirect(route('branch-services.index'));

        $this->assertDatabaseMissing('branch_services', ['id' => $branchService->id]);
    });

    it('ensures BranchService pivot has no SoftDeletes trait', function () {
        $traits = class_uses_recursive(BranchService::class);

        expect($traits)->not->toContain(\Illuminate\Database\Eloquent\SoftDeletes::class);
    });
});
