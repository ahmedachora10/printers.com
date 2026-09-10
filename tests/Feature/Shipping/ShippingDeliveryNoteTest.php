<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Delivery note and the shipping line on screen', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id, 'vat_rate_override' => 15]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'full_name' => 'خالد العتيبي',
            'phone' => '0501112223',
        ]);

        $this->provider = DeliveryProvider::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'أبو محمد',
            'phone' => '0509998887',
        ]);

        $this->zone = DeliveryZone::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'حي النرجس',
            'price' => 20,
        ]);

        $this->invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-001-00001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 100,
            'shipping_fee' => 20,
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
            'shipping_address' => 'حي النرجس، مكتب 12',
            'shipping_distance_km' => 3.5,
            'vat_pct' => 15,
            'vat_amount' => 15.65,
            'total_amount' => 120,
            'employee_commission' => 40,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);

        $this->invoice->lines()->create([
            'branch_service_id' => null,
            'service_name' => 'طباعة بنر',
            'qty' => 2,
            'unit_price' => 50,
            'discount_pct' => 0,
            'subtotal' => 100,
            'commission_pct' => 40,
            'commission_amount' => 40,
        ]);

        $this->actingAs($this->admin);
    });

    // ── سطر التوصيل في العرض والطباعة ─────────────────────────────

    it('shows the shipping fee on the invoice screen', function () {
        $this->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.shippingFee', 20)
                ->where('invoice.shippingProviderName', 'أبو محمد'));
    });

    it('carries the shipping fee onto the printed invoice', function () {
        $this->get(route('invoices.print', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.shippingFee', 20));
    });

    it('reports no shipping on a product invoice', function () {
        // المورد متعدّد الأشكال: فاتورة المنتجات بلا أعمدة شحن، فتقرأ null
        // ولا يُطبع لها سطر توصيل.
        $product = ProductInvoice::create([
            'invoice_number' => 'INV-001-00001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 50,
            'vat_pct' => 15,
            'vat_amount' => 6.52,
            'total_amount' => 50,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);

        $this->get(route('invoices.show', ['type' => 'product', 'id' => $product->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.shippingFee', null));
    });

    // ── بيان التوصيل ──────────────────────────────────────────────

    it('renders the delivery note with what the driver needs', function () {
        $this->get(route('invoices.service.delivery-note', $this->invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/delivery-note')
                ->where('note.invoiceNumber', 'SINV-001-00001')
                ->where('note.customerName', 'خالد العتيبي')
                ->where('note.customerPhone', '0501112223')
                ->where('note.address', 'حي النرجس، مكتب 12')
                ->where('note.zoneName', 'حي النرجس')
                ->where('note.distanceKm', 3.5)
                ->where('note.providerName', 'أبو محمد')
                ->where('note.providerPhone', '0509998887')
                ->where('note.shippingFee', 20)
                ->has('note.lines', 1)
                ->where('note.lines.0.name', 'طباعة بنر'));
    });

    it('never puts a price or a commission on the driver sheet', function () {
        $this->get(route('invoices.service.delivery-note', $this->invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // السائق طرفٌ خارجيّ: لا يرى بم باع المركز ولا ما كسبه الموظف.
                ->missing('note.lines.0.unitPrice')
                ->missing('note.lines.0.subtotal')
                ->missing('note.subtotal')
                ->missing('note.totalAmount')
                ->missing('note.employeeCommission')
                // ولا رقم ضريبي: بيانٌ داخليّ لا فاتورة ضريبية.
                ->where('branch.taxNumber', null));
    });

    it('refuses a delivery note for an invoice with no shipping', function () {
        $plain = ServiceInvoice::create([
            'invoice_number' => 'SINV-001-00002',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 13.04,
            'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::PAID,
        ]);

        $this->get(route('invoices.service.delivery-note', $plain))->assertNotFound();
    });

    it('lets the accountant open the delivery note too', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        // الورقة في مجموعة الفواتير لا نقطة البيع، فيبلغها المحاسب.
        $this->actingAs($accountant)
            ->get(route('invoices.service.delivery-note', $this->invoice))
            ->assertOk();
    });

    it('keeps the delivery note out of another branch reach', function () {
        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
        Branch::factory()->create(['owner_id' => $otherAdmin->id]);

        $this->actingAs($otherAdmin)
            ->get(route('invoices.service.delivery-note', $this->invoice))
            ->assertForbidden();
    });
});
