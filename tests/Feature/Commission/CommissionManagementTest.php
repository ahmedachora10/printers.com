<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\CommissionPayment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Commission System', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->superAdmin);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('allows super-admin to view the commissions page', function () {
        $this->get(route('commissions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('commissions/index')
                ->has('employees')
                ->has('summary'));
    });

    it('allows branch-admin to view the commissions page', function () {
        $this->actingAs($this->branchAdmin)
            ->get(route('commissions.index'))
            ->assertOk();
    });

    it('prevents an employee from viewing the commissions page', function () {
        $this->actingAs($this->employee)
            ->get(route('commissions.index'))
            ->assertForbidden();
    });

    // ── AGGREGATION ────────────────────────────────────────────────

    it('aggregates earned, paid, pending and tahazir per employee', function () {
        CommissionLedger::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 100,
            'is_tahazir' => false,
            'paid_at' => now(),
        ]);
        CommissionLedger::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 40,
            'is_tahazir' => true,
            'paid_at' => null,
        ]);

        $this->get(route('commissions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.totalEarned', 140)
                ->where('summary.totalPaid', 100)
                ->where('summary.pending', 40)
                ->where('employees.0.tahazirEarned', 40)
                ->where('employees.0.pending', 40));
    });

    // ── PAY FLOW ───────────────────────────────────────────────────

    it('records a payment and stamps paid_at on covered ledger entries', function () {
        CommissionLedger::factory()->count(2)->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 75,
            'earned_at' => now()->subDays(2),
            'paid_at' => null,
        ]);

        $this->post(route('commissions.pay'), [
            'user_id' => $this->employee->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertRedirect(route('commissions.index'));

        $payment = CommissionPayment::query()->first();
        expect($payment)->not->toBeNull();
        expect((float) $payment->total_amount)->toBe(150.0);
        expect($payment->user_id)->toBe($this->employee->id);
        expect($payment->paid_by)->toBe($this->superAdmin->id);

        expect(CommissionLedger::whereNull('paid_at')->where('user_id', $this->employee->id)->count())->toBe(0);
    });

    it('only settles ledger entries earned within the selected period', function () {
        $inside = CommissionLedger::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 50,
            'earned_at' => now()->subDays(3),
            'paid_at' => null,
        ]);
        $outside = CommissionLedger::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 90,
            'earned_at' => now()->subMonths(3),
            'paid_at' => null,
        ]);

        $this->post(route('commissions.pay'), [
            'user_id' => $this->employee->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertRedirect();

        expect((float) CommissionPayment::query()->first()->total_amount)->toBe(50.0);
        expect($inside->fresh()->paid_at)->not->toBeNull();
        expect($outside->fresh()->paid_at)->toBeNull();
    });

    it('fails with a validation error when nothing is pending in the period', function () {
        CommissionLedger::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 60,
            'earned_at' => now(),
            'paid_at' => now(),
        ]);

        $this->post(route('commissions.pay'), [
            'user_id' => $this->employee->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertSessionHasErrors('period_start');

        expect(CommissionPayment::query()->count())->toBe(0);
    });

    it('prevents a branch-admin from paying an employee in another branch', function () {
        $otherBranch = Branch::factory()->create();
        $otherEmployee = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherEmployee->addRole(Roles::EMPLOYEE->value);

        CommissionLedger::factory()->create([
            'user_id' => $otherEmployee->id,
            'branch_id' => $otherBranch->id,
            'amount' => 100,
            'earned_at' => now(),
            'paid_at' => null,
        ]);

        $this->actingAs($this->branchAdmin)
            ->post(route('commissions.pay'), [
                'user_id' => $otherEmployee->id,
                'period_start' => now()->subWeek()->toDateString(),
                'period_end' => now()->toDateString(),
            ])->assertForbidden();

        expect(CommissionPayment::query()->count())->toBe(0);
    });
});
