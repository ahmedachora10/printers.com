<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\City;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Service note examples', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->city = City::factory()->create();
        $this->template = ServiceTemplate::factory()->create();

        // Branch admins are linked through branches.owner_id, not users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create([
            'city_id' => $this->city->id,
            'owner_id' => $this->branchAdmin->id,
        ]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin);
    });

    function attachPayload(array $overrides = []): array
    {
        return array_merge([
            'service_template_id' => test()->template->id,
            'branch_id' => test()->branch->id,
            'base_commission_pct' => 10.00,
            'max_discount_pct' => 5.00,
            'is_tahazir' => false,
            'is_active' => true,
        ], $overrides);
    }

    it('stores the examples a branch admin sets on a service', function () {
        $this->post(route('branch-services.store'), attachPayload([
            'note_examples' => ['طباعة وجهين', 'تغليف حراري', 'تسليم خلال 24 ساعة'],
        ]))->assertRedirect();

        $service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();

        expect($service->note_examples)->toBe(['طباعة وجهين', 'تغليف حراري', 'تسليم خلال 24 ساعة']);
    });

    it('trims blanks and duplicates before saving', function () {
        $this->post(route('branch-services.store'), attachPayload([
            'note_examples' => ['  طباعة وجهين  ', '', '   ', 'طباعة وجهين', 'تغليف حراري'],
        ]))->assertRedirect();

        $service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();

        // Reindexed too — a JSON array, never an object with gaps.
        expect($service->note_examples)->toBe(['طباعة وجهين', 'تغليف حراري']);
    });

    it('rejects more than ten examples', function () {
        $this->post(route('branch-services.store'), attachPayload([
            'note_examples' => array_map(fn (int $i) => "مثال {$i}", range(1, 11)),
        ]))->assertSessionHasErrors('note_examples');

        $this->assertDatabaseCount('branch_services', 0);
    });

    it('rejects an example longer than 120 characters', function () {
        $this->post(route('branch-services.store'), attachPayload([
            'note_examples' => [str_repeat('ا', 121)],
        ]))->assertSessionHasErrors('note_examples.0');
    });

    it('defaults to an empty list when no examples are sent', function () {
        $this->post(route('branch-services.store'), attachPayload())->assertRedirect();

        $service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();

        expect($service->note_examples)->toBe([]);
    });

    it('replaces and clears the list on update', function () {
        $this->post(route('branch-services.store'), attachPayload([
            'note_examples' => ['طباعة وجهين'],
        ]))->assertRedirect();

        $service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();

        $this->put(route('branch-services.update', $service->id), [
            'base_commission_pct' => 10.00,
            'max_discount_pct' => 5.00,
            'note_examples' => ['تغليف حراري'],
            'is_tahazir' => false,
            'is_active' => true,
        ])->assertRedirect();

        expect($service->fresh()->note_examples)->toBe(['تغليف حراري']);

        $this->put(route('branch-services.update', $service->id), [
            'base_commission_pct' => 10.00,
            'max_discount_pct' => 5.00,
            'note_examples' => [],
            'is_tahazir' => false,
            'is_active' => true,
        ])->assertRedirect();

        expect($service->fresh()->note_examples)->toBe([]);
    });

    it('ships the examples to the service POS', function () {
        $this->post(route('branch-services.store'), attachPayload([
            'note_examples' => ['طباعة وجهين', 'تغليف حراري'],
        ]))->assertRedirect();

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/index')
                ->where('services.0.noteExamples', ['طباعة وجهين', 'تغليف حراري'])
            );
    });

    it('sends an empty list to the POS for a service with no examples', function () {
        $this->post(route('branch-services.store'), attachPayload())->assertRedirect();

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('services.0.noteExamples', []));
    });
});
