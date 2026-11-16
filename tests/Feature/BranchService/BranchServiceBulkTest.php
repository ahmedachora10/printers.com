<?php

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\City;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $city = City::factory()->create();
    $this->template = ServiceTemplate::factory()->create();
    $this->linked = Branch::factory()->create(['city_id' => $city->id]);
    $this->fresh = Branch::factory()->create(['city_id' => $city->id]);

    $this->template->branches()->attach($this->linked->id, [
        'base_commission_pct' => 10, 'max_discount_pct' => 5, 'is_active' => true,
    ]);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole('super-admin');
    $this->actingAs($this->superAdmin);
});

function bulkPayload(array $overrides = []): array
{
    return [
        'branch_ids' => [test()->linked->id, test()->fresh->id],
        'fields' => ['base_commission_pct'],
        'base_commission_pct' => 20,
        'max_discount_pct' => 9,
        'pricing_type' => 'unit',
        'price_per_sqm' => 0,
        'agent_commission_per_sqm' => 0,
        'max_selling_price' => null,
        'min_selling_price' => null,
        'note_examples' => [],
        'has_materials' => false,
        'materials_cost' => 0,
        'is_active' => true,
        ...$overrides,
    ];
}

it('updates only the chosen fields on linked branches and links new ones with every value', function () {
    $this->post(route('service-templates.branches.bulk-upsert', $this->template), bulkPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $linked = BranchService::where('branch_id', $this->linked->id)->sole();
    expect((float) $linked->base_commission_pct)->toBe(20.0)
        ->and((float) $linked->max_discount_pct)->toBe(5.0); // not chosen → kept

    $fresh = BranchService::where('branch_id', $this->fresh->id)->sole();
    expect((float) $fresh->base_commission_pct)->toBe(20.0)
        ->and((float) $fresh->max_discount_pct)->toBe(9.0);
});

it('rejects a floor above the cap and writes nothing', function () {
    $this->post(route('service-templates.branches.bulk-upsert', $this->template), bulkPayload([
        'fields' => ['price_bounds'], 'max_selling_price' => 10, 'min_selling_price' => 50,
    ]))->assertSessionHasErrors('min_selling_price');

    expect(BranchService::where('branch_id', $this->fresh->id)->exists())->toBeFalse();
});

it('only links a branch-owned template to its own branch', function () {
    $this->template->update(['branch_id' => $this->linked->id]);

    $this->post(route('service-templates.branches.bulk-upsert', $this->template), bulkPayload())
        ->assertSessionHasErrors('branch_ids.1');
});

it('detaches the selected branches in one go', function () {
    $id = BranchService::where('branch_id', $this->linked->id)->value('id');

    $this->delete(route('service-templates.branches.bulk-destroy', $this->template), ['ids' => [$id]])
        ->assertRedirect();

    expect(BranchService::count())->toBe(0);
});

it('is closed to branch admins', function () {
    $admin = User::factory()->create();
    $admin->addRole('branch-admin');

    $this->actingAs($admin)
        ->post(route('service-templates.branches.bulk-upsert', $this->template), bulkPayload())
        ->assertForbidden();
});
