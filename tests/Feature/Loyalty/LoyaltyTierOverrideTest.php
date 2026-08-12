<?php

use App\Enums\CustomerTierEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * التنزيل اليدوي للمستوى: المنفذ الوحيد لخفض مستوى الولاء، إذ يرقّي المحرّك
 * التلقائي ولا ينزّل أبداً.
 */
describe('Manual tier override', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => 'individual',
            'agent_id' => null,
            'tier' => CustomerTierEnum::Gold,
            'cumulative_spend' => 6000,
            'points_balance' => 0,
        ]);

        $this->actingAs($this->branchAdmin);
    });

    it('downgrades a tier for the branch admin', function () {
        $this->patch(route('customers.override-tier', $this->customer), [
            'tier' => 'silver',
            'reason' => 'تصحيح مستوى نتج عن فواتير مرتجعة',
        ])->assertRedirect();

        expect($this->customer->refresh()->tier)->toBe(CustomerTierEnum::Silver)
            // تُرك الإنفاق فارغاً فبقي كما هو
            ->and((float) $this->customer->cumulative_spend)->toBe(6000.00);
    });

    it('corrects the cumulative spend alongside the tier', function () {
        $this->patch(route('customers.override-tier', $this->customer), [
            'tier' => 'bronze',
            'cumulative_spend' => 800,
            'reason' => 'تصحيح الإنفاق بعد مرتجعات قديمة',
        ])->assertRedirect();

        expect($this->customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
            ->and((float) $this->customer->cumulative_spend)->toBe(800.00);
    });

    it('records the change in the activity log with its reason', function () {
        $this->patch(route('customers.override-tier', $this->customer), [
            'tier' => 'none',
            'cumulative_spend' => 0,
            'reason' => 'حساب تجريبي',
        ])->assertRedirect();

        $entry = Activity::query()
            ->where('description', 'تعديل يدوي لمستوى الولاء')
            ->latest('id')
            ->firstOrFail();

        expect($entry->causer_id)->toBe($this->branchAdmin->id)
            ->and($entry->properties['from_tier'])->toBe('gold')
            ->and($entry->properties['to_tier'])->toBe('none')
            // خصائص السجلّ تمرّ بـ JSON، فتعود 6000.0 عدداً صحيحاً — toEqual لا toBe.
            ->and($entry->properties['from_cumulative_spend'])->toEqual(6000.00)
            ->and($entry->properties['to_cumulative_spend'])->toEqual(0.0)
            ->and($entry->properties['reason'])->toBe('حساب تجريبي');
    });

    it('promotes as well as downgrades', function () {
        $this->customer->update(['tier' => CustomerTierEnum::None, 'cumulative_spend' => 0]);

        $this->patch(route('customers.override-tier', $this->customer), [
            'tier' => 'gold',
            'reason' => 'عميل مميز بقرار الإدارة',
        ])->assertRedirect();

        expect($this->customer->refresh()->tier)->toBe(CustomerTierEnum::Gold);
    });

    it('requires a reason', function () {
        $this->patch(route('customers.override-tier', $this->customer), [
            'tier' => 'silver',
        ])->assertSessionHasErrors('reason');

        expect($this->customer->refresh()->tier)->toBe(CustomerTierEnum::Gold);
    });

    it('rejects a tier outside the enum', function () {
        $this->patch(route('customers.override-tier', $this->customer), [
            'tier' => 'platinum',
            'reason' => 'اختبار',
        ])->assertSessionHasErrors('tier');
    });

    it('forbids an employee from overriding a tier', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->patch(route('customers.override-tier', $this->customer), [
                'tier' => 'gold',
                'reason' => 'محاولة',
            ])->assertForbidden();

        expect($this->customer->refresh()->tier)->toBe(CustomerTierEnum::Gold);
    });

    it('forbids a branch admin from another branch', function () {
        $otherBranch = Branch::factory()->create();
        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $otherBranch->update(['owner_id' => $otherAdmin->id]);

        $this->actingAs($otherAdmin)
            ->patch(route('customers.override-tier', $this->customer), [
                'tier' => 'none',
                'reason' => 'محاولة',
            ])->assertForbidden();
    });

    it('exposes the ability on the customer page', function () {
        $this->get(route('customers.show', $this->customer))
            ->assertInertia(fn ($page) => $page->where('canOverrideTier', true));
    });

});
