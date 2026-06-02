<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('User Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->superAdmin);
    });

    // ── INDEX / AUTHORIZATION ──────────────────────────────────────

    it('allows super-admin to view the user list', function () {
        $this->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('users/index'));
    });

    it('allows branch-admin to view the user list', function () {
        $this->actingAs($this->branchAdmin);

        $this->get(route('users.index'))->assertOk();
    });

    it('prevents employee from viewing the user list', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->get(route('users.index'))->assertForbidden();
    });

    it('scopes branch-admin list to their own branch staff', function () {
        $mine = User::factory()->create(['branch_id' => $this->branch->id]);
        $mine->addRole(Roles::EMPLOYEE->value);

        $otherBranch = Branch::factory()->create();
        $theirs = User::factory()->create(['branch_id' => $otherBranch->id]);
        $theirs->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->get(route('users.index'))
            ->assertInertia(fn ($page) => $page->where('users.data', fn ($data) => collect($data)->pluck('id')->contains($mine->id)
                && ! collect($data)->pluck('id')->contains($theirs->id)));
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates a user with a role and hashed password', function () {
        $this->post(route('users.store'), [
            'name'      => 'موظف جديد',
            'username'  => 'newstaff',
            'email'     => 'newstaff@example.com',
            'password'  => 'password123',
            'password_confirmation' => 'password123',
            'role'      => Roles::EMPLOYEE->value,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        $user = User::where('username', 'newstaff')->first();

        expect($user)->not->toBeNull()
            ->and($user->hasRole(Roles::EMPLOYEE->value))->toBeTrue()
            ->and(Hash::check('password123', $user->password))->toBeTrue();
    });

    it('fails to create a user without required fields', function () {
        $this->post(route('users.store'), [])
            ->assertSessionHasErrors(['name', 'username', 'email', 'password', 'role']);
    });

    it('fails to create a user with a duplicate username', function () {
        User::factory()->create(['username' => 'taken']);

        $this->post(route('users.store'), [
            'name'      => 'مكرر',
            'username'  => 'taken',
            'email'     => 'unique@example.com',
            'password'  => 'password123',
            'password_confirmation' => 'password123',
            'role'      => Roles::EMPLOYEE->value,
        ])->assertSessionHasErrors(['username']);
    });

    it('forces a branch-admin created user into the admin own branch', function () {
        $otherBranch = Branch::factory()->create();

        $this->actingAs($this->branchAdmin)->post(route('users.store'), [
            'name'      => 'تابع للفرع',
            'username'  => 'branchstaff',
            'email'     => 'branchstaff@example.com',
            'password'  => 'password123',
            'password_confirmation' => 'password123',
            'role'      => Roles::EMPLOYEE->value,
            'branch_id' => $otherBranch->id, // attempt to escape own branch
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'username'  => 'branchstaff',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('prevents branch-admin from assigning privileged roles', function () {
        $this->actingAs($this->branchAdmin)->post(route('users.store'), [
            'name'      => 'محاولة',
            'username'  => 'wannabe',
            'email'     => 'wannabe@example.com',
            'password'  => 'password123',
            'password_confirmation' => 'password123',
            'role'      => Roles::SUPER_ADMIN->value,
        ])->assertSessionHasErrors(['role']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a user and changes their role', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->put(route('users.update', $user), [
            'name'      => 'تمت الترقية',
            'username'  => $user->username,
            'email'     => $user->email,
            'role'      => Roles::ACCOUNTANT->value,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        $user->refresh();

        expect($user->name)->toBe('تمت الترقية')
            ->and($user->hasRole(Roles::ACCOUNTANT->value))->toBeTrue()
            ->and($user->hasRole(Roles::EMPLOYEE->value))->toBeFalse();
    });

    it('keeps the existing password when none is provided on update', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole(Roles::EMPLOYEE->value);
        $originalHash = $user->password;

        $this->put(route('users.update', $user), [
            'name'      => $user->name,
            'username'  => $user->username,
            'email'     => $user->email,
            'password'  => '',
            'role'      => Roles::EMPLOYEE->value,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        expect($user->refresh()->password)->toBe($originalHash);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles user status', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->patch(route('users.toggle-status', $user))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('soft-deletes a user', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->delete(route('users.destroy', $user))
            ->assertRedirect(route('users.index'));

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    });

    it('prevents deleting your own account', function () {
        $this->delete(route('users.destroy', $this->superAdmin))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->superAdmin->id, 'deleted_at' => null]);
    });

    it('prevents branch-admin from managing a user in another branch', function () {
        $otherBranch = Branch::factory()->create();
        $other = User::factory()->create(['branch_id' => $otherBranch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->delete(route('users.destroy', $other))
            ->assertForbidden();
    });

    it('prevents branch-admin from managing another branch-admin', function () {
        $peer = User::factory()->create(['branch_id' => $this->branch->id]);
        $peer->addRole(Roles::BRANCH_ADMIN->value);

        $this->actingAs($this->branchAdmin)
            ->put(route('users.update', $peer), [
                'name'     => 'x',
                'username' => $peer->username,
                'email'    => $peer->email,
                'role'     => Roles::EMPLOYEE->value,
            ])->assertForbidden();
    });

    // ── SHOW ───────────────────────────────────────────────────────

    it('allows super-admin to view any user profile', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->get(route('users.show', $user))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('users/show')
                ->where('user.id', $user->id)
                ->has('commissionSummary')
                ->has('salesSummary'));
    });

    it('allows branch-admin to view a user in their own branch', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->get(route('users.show', $user))
            ->assertOk();
    });

    it('prevents branch-admin from viewing a user in another branch', function () {
        $otherBranch = Branch::factory()->create();
        $other = User::factory()->create(['branch_id' => $otherBranch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->get(route('users.show', $other))
            ->assertForbidden();
    });

    it('prevents branch-admin from viewing another branch-admin profile', function () {
        $peer = User::factory()->create(['branch_id' => $this->branch->id]);
        $peer->addRole(Roles::BRANCH_ADMIN->value);

        $this->actingAs($this->branchAdmin)
            ->get(route('users.show', $peer))
            ->assertForbidden();
    });

    it('aggregates the commission ledger on the profile', function () {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole(Roles::EMPLOYEE->value);

        \Illuminate\Support\Facades\DB::table('commission_ledger')->insert([
            [
                'user_id' => $user->id, 'branch_id' => $this->branch->id,
                'invoice_line_id' => 1, 'invoice_line_type' => 'service',
                'amount' => 100, 'is_tahazir' => false, 'source_type' => 'standard',
                'earned_at' => now(), 'paid_at' => now(),
            ],
            [
                'user_id' => $user->id, 'branch_id' => $this->branch->id,
                'invoice_line_id' => 2, 'invoice_line_type' => 'service',
                'amount' => 40, 'is_tahazir' => true, 'source_type' => 'standard',
                'earned_at' => now(), 'paid_at' => null,
            ],
        ]);

        $this->get(route('users.show', $user))
            ->assertInertia(fn ($page) => $page
                ->where('commissionSummary.totalEarned', 140)
                ->where('commissionSummary.totalPaid', 100)
                ->where('commissionSummary.pending', 40)
                ->where('commissionSummary.tahazirEarned', 40)
                ->where('commissionSummary.standardEarned', 100));
    });
});
