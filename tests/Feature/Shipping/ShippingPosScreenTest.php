<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Shipping on the service POS screen', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $template = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 50,
            'max_discount_pct' => 50,
            'is_tahazir' => false,
            'is_active' => true,
        ]);
        $this->service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();

        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 50,
        ]);

        $this->provider = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id]);
        $this->zone = DeliveryZone::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'حي النرجس',
            'price' => 20,
        ]);

        $this->actingAs($this->employee);
    });

    it('hands the POS the branch providers and zones', function () {
        // مزوّدٌ وشريحةٌ من فرعٍ آخر لا يتسرّبان إلى المنتقي.
        DeliveryProvider::factory()->create();
        DeliveryZone::factory()->create();

        $this->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shippingProviders', 1)
                ->where('shippingProviders.0.id', $this->provider->id)
                ->has('shippingZones', 1)
                ->where('shippingZones.0.id', $this->zone->id));
    });

    it('hides inactive providers and zones from the POS', function () {
        DeliveryProvider::factory()->inactive()->create(['branch_id' => $this->branch->id]);
        DeliveryZone::factory()->inactive()->create(['branch_id' => $this->branch->id]);

        $this->get(route('pos.service.create'))
            ->assertInertia(fn ($page) => $page->has('shippingProviders', 1)->has('shippingZones', 1));
    });

    it('locks the fee for an employee and opens it for a reviewer', function () {
        $this->get(route('pos.service.create'))
            ->assertInertia(fn ($page) => $page->where('canEditShippingFee', false));

        $this->actingAs($this->branchAdmin)
            ->get(route('pos.service.create'))
            ->assertInertia(fn ($page) => $page->where('canEditShippingFee', true));
    });

    it('orders areas before distance bands in the picker', function () {
        DeliveryZone::factory()->distance(0, 5)->create(['branch_id' => $this->branch->id]);

        // الأحياء أولاً: الطريق الأغلب في نقطة البيع.
        $this->get(route('pos.service.create'))
            ->assertInertia(fn ($page) => $page
                ->where('shippingZones.0.type', 'area')
                ->where('shippingZones.1.type', 'distance')
                ->where('shippingZones.1.rangeLabel', '0 – 5 كم'));
    });

    it('carries the customer address book onto the POS card', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'label' => 'المكتب',
            'address' => 'طريق الملك فهد',
            'delivery_zone_id' => $this->zone->id,
            'is_default' => true,
        ]);

        $this->get(route('pos.customers.search', ['q' => $customer->full_name]))
            ->assertOk()
            ->assertJsonPath('data.0.addresses.0.displayLabel', 'المكتب — طريق الملك فهد')
            ->assertJsonPath('data.0.addresses.0.deliveryZoneId', $this->zone->id);
    });

    it('returns the shipping to the edit screen as it was saved', function () {
        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId($this->branch->id),
            'status' => 'due',
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
            'shipping_address' => 'حي النرجس، مكتب 12',
            'shipping_distance_km' => 3.5,
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 100,
                'discount_pct' => 0,
            ]],
        ])->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        // إعادة فتح الفاتورة لا تُسقط سائقاً ولا عنواناً، فحفظها ثانيةً لا يمحوهما.
        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.shippingProviderId', $this->provider->id)
                ->where('invoice.shippingZoneId', $this->zone->id)
                ->where('invoice.shippingFee', 20)
                ->where('invoice.shippingDistanceKm', 3.5)
                ->where('invoice.shippingAddress', 'حي النرجس، مكتب 12'));
    });
});
