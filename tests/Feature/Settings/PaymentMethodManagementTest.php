<?php

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('PaymentMethod Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create(['branch_id' => null]);
        $this->branchAdmin->addRole('branch-admin');
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);

        $this->actingAs($this->branchAdmin);
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('allows branch-admin to create a payment method', function () {
        $this->post(route('payment-methods.store'), [
            'name'      => 'نقد',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['name' => 'نقد']);
    });

    it('fails to create a payment method without a name', function () {
        $this->post(route('payment-methods.store'), [])
            ->assertSessionHasErrors(['name']);
    });

    it('fails to create a duplicate payment method name in the same branch', function () {
        PaymentMethod::factory()->create([
            'name'      => 'نقد',
            'branch_id' => $this->branchAdmin->branchId,
        ]);

        $this->post(route('payment-methods.store'), ['name' => 'نقد'])
            ->assertSessionHasErrors(['name']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('allows branch-admin to update a payment method', function () {
        $pm = PaymentMethod::factory()->create(['branch_id' => $this->branchAdmin->branchId]);

        $this->put(route('payment-methods.update', $pm), ['name' => 'تحويل بنكي'])
            ->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['id' => $pm->id, 'name' => 'تحويل بنكي']);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles payment method status', function () {
        $pm = PaymentMethod::factory()->create([
            'branch_id' => $this->branchAdmin->branchId,
            'is_active' => true,
        ]);

        $this->patch(route('payment-methods.toggle-status', $pm))
            ->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['id' => $pm->id, 'is_active' => false]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('soft-deletes a payment method with no invoice references', function () {
        $pm = PaymentMethod::factory()->create(['branch_id' => $this->branchAdmin->branchId]);

        $this->delete(route('payment-methods.destroy', $pm))->assertRedirect();

        $this->assertSoftDeleted('payment_methods', ['id' => $pm->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents employee from creating payment methods', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->post(route('payment-methods.store'), ['name' => 'نقد'])
            ->assertForbidden();
    });

    it('prevents branch-admin from deleting another branch payment method', function () {
        $otherBranch = Branch::factory()->create();
        $pm          = PaymentMethod::factory()->create(['branch_id' => $otherBranch->id]);

        $this->delete(route('payment-methods.destroy', $pm))->assertForbidden();
    });
});
