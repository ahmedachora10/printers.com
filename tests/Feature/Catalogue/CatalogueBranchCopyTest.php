<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * دليل مستقل لكل فرع: the super admin creates a category for several branches
 * at once (one independent copy each) and copies one branch's own catalogue
 * into another — merged by name, nothing deleted, general parents reused.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

    $this->source = Branch::factory()->create();
    $this->target = Branch::factory()->create();

    $this->price = fn (CatalogSubcategory $sub, ?int $branchId, string $name, float $base) => CatalogPrice::create([
        'subcategory_id' => $sub->id, 'branch_id' => $branchId, 'name' => $name,
        'min_price' => 1, 'max_price' => 100, 'base_price' => $base, 'is_active' => true,
    ]);
});

it('creates one independent category per chosen branch, image included', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.catalogue.categories.store'), [
            'name_ar' => 'لوحات',
            'branch_ids' => [$this->source->id, $this->target->id],
            'image' => UploadedFile::fake()->image('a.png'),
        ])->assertSessionHasNoErrors();

    $copies = CatalogCategory::where('name_ar', 'لوحات')->get();

    expect($copies->pluck('branch_id')->sort()->values()->all())->toBe([$this->source->id, $this->target->id])
        ->and($copies->every(fn ($c) => $c->getFirstMedia('image') !== null))->toBeTrue();
});

it('refuses a name one of the chosen branches already sees', function () {
    CatalogCategory::create(['name_ar' => 'لوحات', 'branch_id' => $this->target->id]);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.catalogue.categories.store'), [
            'name_ar' => 'لوحات',
            'branch_ids' => [$this->source->id, $this->target->id],
        ])->assertSessionHasErrors('name_ar');

    expect(CatalogCategory::where('name_ar', 'لوحات')->count())->toBe(1);
});

it('previews a copy without writing, then merges it into the target', function () {
    $general = CatalogCategory::create(['name_ar' => 'طباعة']);
    $generalSub = CatalogSubcategory::create(['name_ar' => 'كروت', 'category_id' => $general->id]);
    ($this->price)($generalSub, $this->source->id, 'كرت فاخر', 40);

    $own = CatalogCategory::create(['name_ar' => 'لوحات', 'branch_id' => $this->source->id]);
    $ownSub = CatalogSubcategory::create(['name_ar' => 'كانفس', 'category_id' => $own->id, 'branch_id' => $this->source->id]);
    ($this->price)($ownSub, $this->source->id, '50×70', 120);

    // The target already re-priced the same item: the copy updates it.
    ($this->price)($generalSub, $this->target->id, 'كرت فاخر', 10);

    $payload = ['from' => $this->source->id, 'to' => $this->target->id];

    $this->actingAs($this->superAdmin)
        ->postJson(route('admin.catalogue.copy'), [...$payload, 'dry_run' => true])
        ->assertOk()->assertJsonPath('dryRun', true);

    expect(CatalogCategory::where('branch_id', $this->target->id)->exists())->toBeFalse();

    $this->postJson(route('admin.catalogue.copy'), $payload)->assertOk();

    // The general parent is reused, never forked into a private copy.
    expect(CatalogCategory::where('name_ar', 'طباعة')->count())->toBe(1)
        ->and(CatalogPrice::where(['branch_id' => $this->target->id, 'name' => 'كرت فاخر'])->value('base_price'))->toEqual(40)
        ->and(CatalogCategory::where(['branch_id' => $this->target->id, 'name_ar' => 'لوحات'])->exists())->toBeTrue()
        ->and(CatalogPrice::where(['branch_id' => $this->target->id, 'name' => '50×70'])->value('base_price'))->toEqual(120)
        // The source is untouched.
        ->and(CatalogPrice::where('branch_id', $this->source->id)->count())->toBe(2);
});

it('keeps copying to the super admin alone', function () {
    $branchAdmin = User::factory()->create();
    $branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    Branch::factory()->create(['owner_id' => $branchAdmin->id]);

    $this->actingAs($branchAdmin)
        ->postJson(route('admin.catalogue.copy'), ['from' => $this->source->id, 'to' => $this->target->id])
        ->assertForbidden();
});
