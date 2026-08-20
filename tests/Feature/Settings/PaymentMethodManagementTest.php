<?php

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('PaymentMethod Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

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
            'name' => 'نقد',
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

    // ── تاسك 59: مدير الفرع يملك طرق فرعه وحدها ────────────────────

    it('lets a branch-admin add a payment method pinned to their own branch', function () {
        $this->actingAs($this->branchAdmin);

        $this->post(route('payment-methods.store'), ['name' => 'شبكة مدى'])
            ->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['name' => 'شبكة مدى', 'branch_id' => $this->branch->id]);
    });

    it('ignores a branch_id sent by a branch-admin and pins their own', function () {
        // القيد على الخادم: لا يُلتفّ عليه بطلب مباشر يحمل فرعاً آخر.
        $otherBranch = Branch::factory()->create();
        $this->actingAs($this->branchAdmin);

        $this->post(route('payment-methods.store'), ['name' => 'شبكة مدى', 'branch_id' => $otherBranch->id])
            ->assertRedirect();

        $this->assertDatabaseHas('payment_methods', ['name' => 'شبكة مدى', 'branch_id' => $this->branch->id]);
        $this->assertDatabaseMissing('payment_methods', ['branch_id' => $otherBranch->id]);
    });

    it('lets a branch-admin edit and delete a method their branch owns', function () {
        $pm = PaymentMethod::factory()->create(['name' => 'شبكة', 'branch_id' => $this->branch->id]);
        $this->actingAs($this->branchAdmin);

        $this->put(route('payment-methods.update', $pm), ['name' => 'شبكة مدى'])->assertRedirect();
        $this->assertDatabaseHas('payment_methods', ['id' => $pm->id, 'name' => 'شبكة مدى']);

        $this->delete(route('payment-methods.destroy', $pm))->assertRedirect();
        $this->assertSoftDeleted('payment_methods', ['id' => $pm->id]);
    });

    it('prevents a branch-admin from touching a global payment method', function () {
        // الصفّ العام يظهر في كل الفروع، فتعديله يغيّر منتقي الدفع عندها جميعاً.
        $pm = PaymentMethod::factory()->create(['branch_id' => null]);
        $this->actingAs($this->branchAdmin);

        $this->put(route('payment-methods.update', $pm), ['name' => 'تحديث'])->assertForbidden();
        $this->delete(route('payment-methods.destroy', $pm))->assertForbidden();
    });

    it('prevents a branch-admin from touching another branch method', function () {
        $otherBranch = Branch::factory()->create();
        $pm = PaymentMethod::factory()->create(['name' => 'شبكة الغير', 'branch_id' => $otherBranch->id]);
        $this->actingAs($this->branchAdmin);

        $this->put(route('payment-methods.update', $pm), ['name' => 'تحديث'])->assertForbidden();
        $this->delete(route('payment-methods.destroy', $pm))->assertForbidden();
    });

    it('rejects a branch method whose name duplicates one the branch already sees', function () {
        PaymentMethod::factory()->create(['name' => 'نقد', 'branch_id' => null]);
        $this->actingAs($this->branchAdmin);

        $this->post(route('payment-methods.store'), ['name' => 'نقد'])
            ->assertSessionHasErrors(['name']);
    });

    it('lets two branches use the same method name independently', function () {
        $otherBranch = Branch::factory()->create();
        PaymentMethod::factory()->create(['name' => 'شبكة الفرع', 'branch_id' => $otherBranch->id]);
        $this->actingAs($this->branchAdmin);

        $this->post(route('payment-methods.store'), ['name' => 'شبكة الفرع'])->assertSessionHasNoErrors()->assertRedirect();

        expect(PaymentMethod::where('name', 'شبكة الفرع')->count())->toBe(2);
    });

    it('hides another branch method from the branch payment options', function () {
        $otherBranch = Branch::factory()->create();
        $mine = PaymentMethod::factory()->create(['name' => 'شبكة فرعي', 'branch_id' => $this->branch->id]);
        $global = PaymentMethod::factory()->create(['name' => 'نقد', 'branch_id' => null]);
        PaymentMethod::factory()->create(['name' => 'شبكة الغير', 'branch_id' => $otherBranch->id]);

        $enabled = $this->branch->enabledPaymentMethods();

        expect($enabled->pluck('id')->all())->toEqualCanonicalizing([$mine->id, $global->id]);
    });

    it('shows a branch-admin the global methods plus their own only', function () {
        $otherBranch = Branch::factory()->create();
        PaymentMethod::factory()->create(['name' => 'نقد', 'branch_id' => null]);
        PaymentMethod::factory()->create(['name' => 'شبكة فرعي', 'branch_id' => $this->branch->id]);
        PaymentMethod::factory()->create(['name' => 'شبكة الغير', 'branch_id' => $otherBranch->id]);

        $this->actingAs($this->branchAdmin)
            ->get(route('app-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('paymentMethods', 2)
                ->where('canManagePaymentMethods', true));
    });

    it('prevents an accountant from creating payment methods', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $this->post(route('payment-methods.store'), ['name' => 'نقد'])
            ->assertForbidden();
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
            'key' => 'enabled_payment_methods',
            'branch_id' => $this->branch->id,
            'value' => json_encode([$pm1->id, $pm2->id]),
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
