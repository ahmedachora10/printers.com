<?php

use App\Enums\CouponDiscountTypeEnum;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Coupon Management', function () {
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

    it('allows branch-admin to view coupon list', function () {
        Coupon::factory()->count(3)->create(['branch_id' => $this->branch->id]);

        $this->get(route('coupons.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('coupons/index'));
    });

    it('prevents employee from viewing coupon list', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('coupons.index'))->assertForbidden();
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates a coupon with valid data', function () {
        $this->post(route('coupons.store'), [
            'code'           => 'SUMMER25',
            'discount_type'  => 'percentage',
            'discount_value' => 25,
            'is_active'      => true,
        ])->assertRedirect(route('coupons.index'));

        $this->assertDatabaseHas('coupons', [
            'code'          => 'summer25',
            'branch_id'     => $this->branch->id,
            'discount_type' => 'percentage',
        ]);
    });

    it('creates a coupon with capacity and expiry', function () {
        $this->post(route('coupons.store'), [
            'code'           => 'LIMITED10',
            'discount_type'  => 'fixed',
            'discount_value' => 50,
            'capacity'       => 100,
            'expires_at'     => now()->addMonth()->toDateString(),
        ])->assertRedirect(route('coupons.index'));

        $this->assertDatabaseHas('coupons', [
            'code'     => 'limited10',
            'capacity' => 100,
        ]);
    });

    it('fails to create coupon without required fields', function () {
        $this->post(route('coupons.store'), [])
            ->assertSessionHasErrors(['code', 'discount_type', 'discount_value']);
    });

    it('fails to create coupon with duplicate code in same branch', function () {
        Coupon::factory()->create([
            'branch_id' => $this->branch->id,
            'code'      => 'promo10',
        ]);

        $this->post(route('coupons.store'), [
            'code'           => 'PROMO10',
            'discount_type'  => 'percentage',
            'discount_value' => 10,
        ])->assertSessionHasErrors(['code']);
    });

    it('allows same code in different branches', function () {
        $otherBranch = Branch::factory()->create();
        Coupon::factory()->create([
            'branch_id' => $otherBranch->id,
            'code'      => 'promo10',
        ]);

        $this->post(route('coupons.store'), [
            'code'           => 'PROMO10',
            'discount_type'  => 'percentage',
            'discount_value' => 10,
        ])->assertRedirect(route('coupons.index'));
    });

    it('fails to create coupon with expired expires_at', function () {
        $this->post(route('coupons.store'), [
            'code'           => 'OLD01',
            'discount_type'  => 'percentage',
            'discount_value' => 10,
            'expires_at'     => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors(['expires_at']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a coupon', function () {
        $coupon = Coupon::factory()->create(['branch_id' => $this->branch->id]);

        $this->put(route('coupons.update', $coupon), [
            'code'           => 'NEWCODE',
            'discount_type'  => 'fixed',
            'discount_value' => 30,
            'is_active'      => true,
        ])->assertRedirect(route('coupons.index'));

        $this->assertDatabaseHas('coupons', [
            'id'            => $coupon->id,
            'code'          => 'newcode',
            'discount_type' => 'fixed',
        ]);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles coupon status from active to inactive', function () {
        $coupon = Coupon::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

        $this->patch(route('coupons.toggle-status', $coupon))
            ->assertRedirect(route('coupons.index'));

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'is_active' => false]);
    });

    it('toggles coupon status from inactive to active', function () {
        $coupon = Coupon::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);

        $this->patch(route('coupons.toggle-status', $coupon))
            ->assertRedirect(route('coupons.index'));

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'is_active' => true]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes a coupon not applied to any invoice', function () {
        $coupon = Coupon::factory()->create(['branch_id' => $this->branch->id]);

        $this->delete(route('coupons.destroy', $coupon))
            ->assertRedirect(route('coupons.index'));

        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    });

    // ── VALIDATE ENDPOINT ──────────────────────────────────────────

    it('validates an active coupon by code', function () {
        Coupon::factory()->create([
            'branch_id'      => $this->branch->id,
            'code'           => 'valid25',
            'discount_type'  => CouponDiscountTypeEnum::Percentage,
            'discount_value' => 25,
            'is_active'      => true,
            'capacity'       => null,
            'expires_at'     => null,
        ]);

        $this->getJson(route('coupons.validate', ['code' => 'VALID25']))
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'type'  => 'percentage',
                'value' => 25,
            ]);
    });

    it('validates is case-insensitive', function () {
        Coupon::factory()->create([
            'branch_id'     => $this->branch->id,
            'code'          => 'mycode',
            'discount_type' => CouponDiscountTypeEnum::Fixed,
            'discount_value' => 50,
            'is_active'     => true,
        ]);

        $this->getJson(route('coupons.validate', ['code' => 'MYCODE']))
            ->assertOk()
            ->assertJsonPath('valid', true);
    });

    it('returns invalid for inactive coupon', function () {
        Coupon::factory()->inactive()->create([
            'branch_id' => $this->branch->id,
            'code'      => 'inactive1',
        ]);

        $this->getJson(route('coupons.validate', ['code' => 'inactive1']))
            ->assertOk()
            ->assertJson(['valid' => false]);
    });

    it('returns invalid for expired coupon', function () {
        Coupon::factory()->expired()->create([
            'branch_id' => $this->branch->id,
            'code'      => 'expired1',
            'is_active' => true,
        ]);

        $this->getJson(route('coupons.validate', ['code' => 'expired1']))
            ->assertOk()
            ->assertJson(['valid' => false]);
    });

    it('returns invalid for exhausted coupon', function () {
        Coupon::factory()->exhausted()->create([
            'branch_id' => $this->branch->id,
            'code'      => 'exhaust1',
            'is_active' => true,
        ]);

        $this->getJson(route('coupons.validate', ['code' => 'exhaust1']))
            ->assertOk()
            ->assertJson(['valid' => false]);
    });

    it('returns remaining_capacity for limited coupon', function () {
        Coupon::factory()->create([
            'branch_id'   => $this->branch->id,
            'code'        => 'limited5',
            'is_active'   => true,
            'capacity'    => 10,
            'used_count'  => 3,
            'expires_at'  => null,
            'discount_type'  => CouponDiscountTypeEnum::Percentage,
            'discount_value' => 10,
        ]);

        $this->getJson(route('coupons.validate', ['code' => 'limited5']))
            ->assertOk()
            ->assertJson([
                'valid'              => true,
                'remaining_capacity' => 7,
            ]);
    });

    it('returns null remaining_capacity for unlimited coupon', function () {
        Coupon::factory()->create([
            'branch_id'      => $this->branch->id,
            'code'           => 'unlimited1',
            'is_active'      => true,
            'capacity'       => null,
            'expires_at'     => null,
            'discount_type'  => CouponDiscountTypeEnum::Fixed,
            'discount_value' => 20,
        ]);

        $this->getJson(route('coupons.validate', ['code' => 'unlimited1']))
            ->assertOk()
            ->assertJsonPath('remaining_capacity', null);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents accountant from creating coupons', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $this->post(route('coupons.store'), [
            'code'           => 'TEST01',
            'discount_type'  => 'percentage',
            'discount_value' => 10,
        ])->assertForbidden();
    });

    it('prevents branch-admin from managing another branch coupon', function () {
        $otherBranch = Branch::factory()->create();
        $coupon      = Coupon::factory()->create(['branch_id' => $otherBranch->id]);

        $this->put(route('coupons.update', $coupon), [
            'code'           => 'HACK',
            'discount_type'  => 'fixed',
            'discount_value' => 100,
        ])->assertForbidden();
    });
});
