<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceShipment;
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
            'shipments' => [[
                'provider_id' => $this->provider->id,
                'zone_id' => $this->zone->id,
                'address' => 'حي النرجس، مكتب 12',
                'distance_km' => 3.5,
            ]],
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
                ->where('invoice.shipments.0.id', $invoice->shipments->first()->id)
                ->where('invoice.shipments.0.providerId', $this->provider->id)
                ->where('invoice.shipments.0.zoneId', $this->zone->id)
                ->where('invoice.shipments.0.fee', 20)
                ->where('invoice.shipments.0.distanceKm', 3.5)
                ->where('invoice.shipments.0.address', 'حي النرجس، مكتب 12'));
    });

    // ── تاسك 170: عدّة طلبات توصيل ───────────────────────────────

    it('sums several shipments into the invoice and keeps each on edit', function () {
        $other = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id]);
        $far = DeliveryZone::factory()->create(['branch_id' => $this->branch->id, 'price' => 35]);
        $lines = [['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0]];

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId($this->branch->id),
            'status' => 'due',
            'shipments' => [
                ['provider_id' => $this->provider->id, 'zone_id' => $this->zone->id, 'address' => 'المكتب'],
                ['provider_id' => $other->id, 'zone_id' => $far->id, 'address' => 'المستودع'],
            ],
            'lines' => $lines,
        ])->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        [$first, $second] = $invoice->shipments->all();

        // 100 خدمات + 20 + 35 توصيل. والعمولة على الخدمات وحدها.
        expect((float) $invoice->shipping_fee)->toEqual(55.00)
            ->and((float) $invoice->total_amount)->toEqual(155.00)
            ->and($invoice->shipments->pluck('address')->all())->toBe(['المكتب', 'المستودع']);

        // التعديل يحدّث الطلب الأول في مكانه ويُسقط الثاني.
        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => paymentMethodId($this->branch->id),
            'shipments' => [['id' => $first->id, 'provider_id' => $this->provider->id, 'zone_id' => $this->zone->id, 'address' => 'المكتب الجديد']],
            'lines' => $lines,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $invoice->refresh();
        expect($invoice->shipments->pluck('id')->all())->toBe([$first->id])
            ->and($invoice->shipments->first()->address)->toBe('المكتب الجديد')
            ->and((float) $invoice->shipping_fee)->toEqual(20.00)
            ->and(ServiceInvoiceShipment::find($second->id))->toBeNull();
    });

    it('refuses to drop a shipment already settled with its driver', function () {
        $lines = [['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0]];

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId($this->branch->id),
            'status' => 'due',
            'shipments' => [['provider_id' => $this->provider->id, 'zone_id' => $this->zone->id]],
            'lines' => $lines,
        ])->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        $shipment = $invoice->shipments->first();
        Expense::query()->forceCreate([
            'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'branch_id' => $this->branch->id,
            'service_invoice_id' => $invoice->id,
            'service_invoice_shipment_id' => $shipment->id,
            'delivery_provider_id' => $this->provider->id,
            'user_id' => $this->employee->id,
            'qty' => 1,
            'unit_price' => 15,
            'total' => 15,
            'paid_from' => 'cash_drawer',
            'date' => today(),
        ]);

        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => paymentMethodId($this->branch->id),
            'shipments' => [],
            'lines' => $lines,
        ])->assertSessionHasErrors('shipments');

        expect($invoice->shipments()->count())->toBe(1);
    });
});
