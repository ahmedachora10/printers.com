<?php

use App\Enums\DeliveryZoneTypeEnum;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 93 — الكوميت الأول: كيانا التوصيل وشاشتهما.
 */
describe('Shipping management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create(['branch_id' => null]);
        $this->superAdmin->addRole('super-admin');

        // فرع مدير الفرع يأتي من `branches.owner_id` لا من عمود `branch_id`.
        $this->branchAdmin = User::factory()->create(['branch_id' => null]);
        $this->branchAdmin->addRole('branch-admin');
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);

        $this->otherBranch = Branch::factory()->create();

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole('employee');
    });

    // ── الوصول ────────────────────────────────────────────────────

    it('opens the shipping screen for a branch admin', function () {
        $this->actingAs($this->branchAdmin)
            ->get(route('shipping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('shipping/index'));
    });

    it('blocks an employee from the shipping screen', function () {
        $this->actingAs($this->employee)
            ->get(route('shipping.index'))
            ->assertForbidden();
    });

    // ── المزوّدون ──────────────────────────────────────────────────

    it('creates a delivery provider pinned to the branch admin own branch', function () {
        $this->actingAs($this->branchAdmin)
            // الفرع المرسل في الطلب يُتجاهَل تماماً ويُثبَّت من المستخدم.
            ->post(route('delivery-providers.store'), [
                'branch_id' => $this->otherBranch->id,
                'name' => 'أبو محمد',
                'type' => 'driver',
                'phone' => '0501234567',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('delivery_providers', [
            'name' => 'أبو محمد',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('requires a branch when a super admin creates a provider', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('delivery-providers.store'), ['name' => 'شركة ثريا', 'type' => 'company'])
            ->assertSessionHasErrors(['branch_id']);
    });

    it('rejects a duplicate provider name within the same branch', function () {
        DeliveryProvider::factory()->create(['branch_id' => $this->branch->id, 'name' => 'أبو محمد']);

        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-providers.store'), ['name' => 'أبو محمد', 'type' => 'driver'])
            ->assertSessionHasErrors(['name']);
    });

    it('allows the same provider name in a different branch', function () {
        DeliveryProvider::factory()->create(['branch_id' => $this->otherBranch->id, 'name' => 'أبو محمد']);

        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-providers.store'), ['name' => 'أبو محمد', 'type' => 'driver'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('stops a branch admin from touching another branch provider', function () {
        $foreign = DeliveryProvider::factory()->create(['branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->branchAdmin)
            ->put(route('delivery-providers.update', $foreign), ['name' => 'مسروق', 'type' => 'driver'])
            ->assertForbidden();
    });

    it('toggles a provider status', function () {
        $provider = DeliveryProvider::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->branchAdmin)
            ->patch(route('delivery-providers.toggle-status', $provider))
            ->assertRedirect();

        expect($provider->fresh()->is_active)->toBeFalse();
    });

    it('soft deletes a provider', function () {
        $provider = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin)
            ->delete(route('delivery-providers.destroy', $provider))
            ->assertRedirect();

        $this->assertSoftDeleted('delivery_providers', ['id' => $provider->id]);
    });

    // ── الشرائح: الحي والمسافة ────────────────────────────────────

    it('creates an area zone and leaves its range empty', function () {
        $this->actingAs($this->branchAdmin)
            // حتى لو أرسلت الواجهة حدوداً، صفّ الحيّ لا يحملها.
            ->post(route('delivery-zones.store'), [
                'type' => 'area',
                'name' => 'حي النرجس',
                'from_km' => 3,
                'to_km' => 9,
                'price' => 25,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $zone = DeliveryZone::firstWhere('name', 'حي النرجس');

        expect($zone->type)->toBe(DeliveryZoneTypeEnum::Area)
            ->and($zone->from_km)->toBeNull()
            ->and($zone->to_km)->toBeNull()
            ->and($zone->branch_id)->toBe($this->branch->id);
    });

    it('creates a distance zone', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'من 0 إلى 5 كم',
                'from_km' => 0,
                'to_km' => 5,
                'price' => 20,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('delivery_zones', [
            'name' => 'من 0 إلى 5 كم',
            'from_km' => '0.00',
            'to_km' => '5.00',
        ]);
    });

    it('creates an open ended distance zone when the upper bound is blank', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'أكثر من 20 كم',
                'from_km' => 20,
                'to_km' => '',
                'price' => 60,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(DeliveryZone::firstWhere('name', 'أكثر من 20 كم')->to_km)->toBeNull();
    });

    it('requires a lower bound on a distance zone', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'بلا بداية',
                'price' => 20,
            ])
            ->assertSessionHasErrors(['from_km']);
    });

    it('accepts a zero price for free delivery', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'area',
                'name' => 'حي مجاني',
                'price' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect((float) DeliveryZone::firstWhere('name', 'حي مجاني')->price)->toBe(0.0);
    });

    // ── حارس التداخل ──────────────────────────────────────────────

    it('rejects a distance zone that overlaps an active one in the same branch', function () {
        DeliveryZone::factory()->distance(0, 5)->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'من 3 إلى 8 كم',
                'from_km' => 3,
                'to_km' => 8,
                'price' => 30,
            ])
            ->assertSessionHasErrors(['from_km']);
    });

    it('allows adjacent distance zones because the range is half open', function () {
        DeliveryZone::factory()->distance(0, 5)->create(['branch_id' => $this->branch->id]);

        // 5 يخصّ الشريحة الثانية وحدها، فلا تداخل بين 0–5 و5–10.
        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'من 5 إلى 10 كم',
                'from_km' => 5,
                'to_km' => 10,
                'price' => 35,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('ignores an inactive zone when checking overlap', function () {
        DeliveryZone::factory()->distance(0, 5)->inactive()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'من 3 إلى 8 كم',
                'from_km' => 3,
                'to_km' => 8,
                'price' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('does not count another branch zones as overlapping', function () {
        DeliveryZone::factory()->distance(0, 5)->create(['branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'من 0 إلى 5 كم',
                'from_km' => 0,
                'to_km' => 5,
                'price' => 20,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('rejects an open ended zone that swallows a later one', function () {
        DeliveryZone::factory()->distance(20, null)->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin)
            ->post(route('delivery-zones.store'), [
                'type' => 'distance',
                'name' => 'من 25 إلى 30 كم',
                'from_km' => 25,
                'to_km' => 30,
                'price' => 80,
            ])
            ->assertSessionHasErrors(['from_km']);
    });

    it('lets a zone keep its own range when edited', function () {
        $zone = DeliveryZone::factory()->distance(0, 5)->create(['branch_id' => $this->branch->id]);

        // لا يُقاس تداخل الصفّ مع نفسه، وإلا استحال تعديل السعر وحده.
        $this->actingAs($this->branchAdmin)
            ->put(route('delivery-zones.update', $zone), [
                'type' => 'distance',
                'name' => $zone->name,
                'from_km' => 0,
                'to_km' => 5,
                'price' => 99,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect((float) $zone->fresh()->price)->toBe(99.0);
    });

    it('toggles a zone status without wiping its range', function () {
        $zone = DeliveryZone::factory()->distance(0, 5)->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin)
            ->patch(route('delivery-zones.toggle-status', $zone))
            ->assertRedirect();

        $fresh = $zone->fresh();

        expect($fresh->is_active)->toBeFalse()
            ->and((float) $fresh->from_km)->toBe(0.0)
            ->and((float) $fresh->to_km)->toBe(5.0);
    });

    // ── مطابقة المسافة ────────────────────────────────────────────

    it('matches a distance to exactly one zone', function () {
        $first = DeliveryZone::factory()->distance(0, 5)->make();
        $second = DeliveryZone::factory()->distance(5, 10)->make();
        $open = DeliveryZone::factory()->distance(20, null)->make();

        expect($first->coversDistance(4.9))->toBeTrue()
            // الحدّ الأعلى غير شامل، فالخامس للشريحة التالية وحدها.
            ->and($first->coversDistance(5))->toBeFalse()
            ->and($second->coversDistance(5))->toBeTrue()
            ->and($open->coversDistance(999))->toBeTrue()
            ->and($open->coversDistance(19))->toBeFalse();
    });

    it('never matches an area zone by distance', function () {
        $area = DeliveryZone::factory()->make(['from_km' => 0, 'to_km' => 100]);

        expect($area->coversDistance(5))->toBeFalse();
    });

    // ── نطاق العرض ────────────────────────────────────────────────

    it('shows a branch admin only their own branch rows', function () {
        DeliveryProvider::factory()->create(['branch_id' => $this->branch->id, 'name' => 'سائق فرعي']);
        DeliveryProvider::factory()->create(['branch_id' => $this->otherBranch->id, 'name' => 'سائق غريب']);

        $this->actingAs($this->branchAdmin)
            ->get(route('shipping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('providers', 1)
                ->where('providers.0.name', 'سائق فرعي'));
    });

    it('shows a super admin every branch until one is picked', function () {
        DeliveryProvider::factory()->create(['branch_id' => $this->branch->id]);
        DeliveryProvider::factory()->create(['branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->superAdmin)
            ->get(route('shipping.index'))
            ->assertInertia(fn ($page) => $page->has('providers', 2));

        $this->actingAs($this->superAdmin)
            ->get(route('shipping.index', ['branch_id' => $this->branch->id]))
            ->assertInertia(fn ($page) => $page->has('providers', 1));
    });
});
