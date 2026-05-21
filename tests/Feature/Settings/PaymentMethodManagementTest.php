<?php

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('PaymentMethod Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create(['branch_id' => null]);
        $this->superAdmin->addRole('super-admin');

        $this->branchAdmin = User::factory()->create(['branch_id' => null]);
        $this->branchAdmin->addRole('branch-admin');
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
    });

    // ── SUPER-ADMIN: STORE ─────────────────────────────────────────

    it('allows super-admin to create a global payment method', function () {
        $this->actingAs($this->superAdmin);

        $this->post(route('payment-methods.store'), [
            'name'      => 'نقد',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['name' => 'نقد']);
    });

    it('fails to create a payment method without a name', function () {
        $this->actingAs($this->superAdmin);

        $this->post(route('payment-methods.store'), [])
            ->assertSessionHasErrors(['name']);
    });

    it('fails to create a duplicate global payment method name', function () {
        PaymentMethod::factory()->create(['name' => 'نقد']);
        $this->actingAs($this->superAdmin);

        $this->post(route('payment-methods.store'), ['name' => 'نقد'])
            ->assertSessionHasErrors(['name']);
    });

    // ── SUPER-ADMIN: UPDATE ────────────────────────────────────────

    it('allows super-admin to update a payment method', function () {
        $pm = PaymentMethod::factory()->create();
        $this->actingAs($this->superAdmin);

        $this->put(route('payment-methods.update', $pm), ['name' => 'تحويل بنكي'])
            ->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['id' => $pm->id, 'name' => 'تحويل بنكي']);
    });

    // ── SUPER-ADMIN: TOGGLE STATUS ─────────────────────────────────

    it('allows super-admin to toggle global payment method status', function () {
        $pm = PaymentMethod::factory()->create(['is_active' => true]);
        $this->actingAs($this->superAdmin);

        $this->patch(route('payment-methods.toggle-status', $pm))
            ->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['id' => $pm->id, 'is_active' => false]);
    });

    // ── SUPER-ADMIN: DELETE ────────────────────────────────────────

    it('allows super-admin to soft-delete a payment method with no invoice references', function () {
        $pm = PaymentMethod::factory()->create();
        $this->actingAs($this->superAdmin);

        $this->delete(route('payment-methods.destroy', $pm))->assertRedirect();

        $this->assertSoftDeleted('payment_methods', ['id' => $pm->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents branch-admin from creating payment methods', function () {
        $this->actingAs($this->branchAdmin);

        $this->post(route('payment-methods.store'), ['name' => 'نقد'])
            ->assertForbidden();
    });

    it('prevents branch-admin from updating payment methods', function () {
        $pm = PaymentMethod::factory()->create();
        $this->actingAs($this->branchAdmin);

        $this->put(route('payment-methods.update', $pm), ['name' => 'تحديث'])
            ->assertForbidden();
    });

    it('prevents branch-admin from deleting payment methods', function () {
        $pm = PaymentMethod::factory()->create();
        $this->actingAs($this->branchAdmin);

        $this->delete(route('payment-methods.destroy', $pm))->assertForbidden();
    });

    it('prevents employee from creating payment methods', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->post(route('payment-methods.store'), ['name' => 'نقد'])
            ->assertForbidden();
    });

    // ── BRANCH-ADMIN: ENABLED PAYMENT METHODS SETTINGS ────────────

    it('allows branch-admin to save enabled payment method ids to settings', function () {
        $pm1 = PaymentMethod::factory()->create(['name' => 'نقد']);
        $pm2 = PaymentMethod::factory()->create(['name' => 'بطاقة بنكية']);
        $this->actingAs($this->branchAdmin);

        $this->put(route('app-settings.update-payment-methods'), [
            'enabled_ids' => [$pm1->id, $pm2->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('settings', [
            'key'       => 'enabled_payment_methods',
            'branch_id' => $this->branch->id,
            'value'     => json_encode([$pm1->id, $pm2->id]),
        ]);
    });

    it('returns only enabled payment methods for a branch', function () {
        $pm1 = PaymentMethod::factory()->create(['name' => 'نقد']);
        $pm2 = PaymentMethod::factory()->create(['name' => 'بطاقة بنكية']);
        PaymentMethod::factory()->create(['name' => 'مدى']);

        Setting::set('enabled_payment_methods', json_encode([$pm1->id, $pm2->id]), $this->branch->id);

        $enabled = $this->branch->enabledPaymentMethods();

        expect($enabled)->toHaveCount(2)
            ->and($enabled->pluck('id')->all())->toContain($pm1->id, $pm2->id);
    });

    it('returns all active methods when no setting is saved for the branch', function () {
        PaymentMethod::factory()->create(['name' => 'نقد', 'is_active' => true]);
        PaymentMethod::factory()->create(['name' => 'بطاقة بنكية', 'is_active' => true]);
        PaymentMethod::factory()->create(['name' => 'مدى', 'is_active' => false]);

        $enabled = $this->branch->enabledPaymentMethods();

        expect($enabled)->toHaveCount(2);
    });
});
