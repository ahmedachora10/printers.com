<?php

use App\Actions\Invoice\GenerateZatcaQrAction;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ProductInvoiceLine;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeProductInvoice(Branch $branch, User $user, array $overrides = []): ProductInvoice
{
    $invoice = ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-'.$branch->id.'-'.fake()->unique()->numerify('#####'),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));

    ProductInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'product_name' => 'منتج تجريبي',
        'qty' => 2,
        'unit_price' => 50,
        'discount_pct' => 0,
        'subtotal' => 100,
    ]);

    return $invoice;
}

function makeServiceInvoice(Branch $branch, User $user, array $overrides = []): ServiceInvoice
{
    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-'.$branch->id.'-'.fake()->unique()->numerify('#####'),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 200,
        'vat_pct' => 15,
        'vat_amount' => 30,
        'total_amount' => 230,
        'employee_commission' => 20,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));

    ServiceInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'service_name' => 'خدمة تجريبية',
        'qty' => 1,
        'unit_price' => 200,
        'discount_pct' => 0,
        'subtotal' => 200,
        'commission_pct' => 10,
        'commission_amount' => 20,
    ]);

    return $invoice;
}

describe('Invoice View (M13)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->actingAs($this->admin);
    });

    it('renders the invoices index for a branch admin', function () {
        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('invoices/index'));
    });

    it('lists both product and service invoices', function () {
        makeProductInvoice($this->branch, $this->admin);
        makeServiceInvoice($this->branch, $this->admin);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 2));
    });

    it('scopes the list to the user branch', function () {
        $other = Branch::factory()->create();
        makeProductInvoice($this->branch, $this->admin);
        makeProductInvoice($other, $this->admin);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1));
    });

    it('filters by type', function () {
        makeProductInvoice($this->branch, $this->admin);
        makeServiceInvoice($this->branch, $this->admin);

        $this->get(route('invoices.index', ['type' => 'service']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.type', 'service'));
    });

    it('limits an accountant to product invoices only', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        makeProductInvoice($this->branch, $accountant);
        makeServiceInvoice($this->branch, $accountant);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.type', 'product'));
    });

    it('limits an employee to service invoices only', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        makeProductInvoice($this->branch, $employee);
        makeServiceInvoice($this->branch, $employee);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.type', 'service'));
    });

    it('shows a product invoice detail', function () {
        $invoice = makeProductInvoice($this->branch, $this->admin);

        $this->get(route('invoices.show', ['type' => 'product', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('invoices/show')
                ->where('invoice.invoiceNumber', $invoice->invoice_number)
                ->where('invoice.type', 'product')
                ->has('invoice.lines', 1));
    });

    it('forbids viewing an invoice from another branch', function () {
        $other = Branch::factory()->create();
        $invoice = makeProductInvoice($other, $this->admin);

        $this->get(route('invoices.show', ['type' => 'product', 'id' => $invoice->id]))
            ->assertForbidden();
    });

    it('returns 404 for an unknown invoice type', function () {
        $invoice = makeProductInvoice($this->branch, $this->admin);

        $this->get('/invoices/unknown/'.$invoice->id)->assertNotFound();
    });

    it('renders the A4 print view by default', function () {
        $invoice = makeServiceInvoice($this->branch, $this->admin);

        $this->get(route('invoices.print', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('invoices/print')
                ->where('format', 'a4')
                ->has('zatcaQr'));
    });

    it('renders the thermal print view when requested', function () {
        $invoice = makeProductInvoice($this->branch, $this->admin);

        $this->get(route('invoices.print', ['type' => 'product', 'id' => $invoice->id, 'format' => 'thermal']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('format', 'thermal'));
    });
});

describe('ZATCA QR generation', function () {
    it('encodes a valid Base64 TLV payload', function () {
        $branch = Branch::factory()->create(['name' => 'فرع الاختبار', 'tax_number' => '300000000000003']);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $invoice = makeProductInvoice($branch, $user, ['total_amount' => 115, 'vat_amount' => 15]);
        $invoice->load('branch');

        $base64 = app(GenerateZatcaQrAction::class)->handle($invoice);
        $tlv = base64_decode($base64, true);

        expect($tlv)->not->toBeFalse();

        // Parse TLV into [tag => value].
        $parsed = [];
        $offset = 0;
        while ($offset < strlen($tlv)) {
            $tag = ord($tlv[$offset]);
            $len = ord($tlv[$offset + 1]);
            $parsed[$tag] = substr($tlv, $offset + 2, $len);
            $offset += 2 + $len;
        }

        expect($parsed)->toHaveKeys([1, 2, 3, 4, 5])
            ->and($parsed[1])->toBe('فرع الاختبار')
            ->and($parsed[2])->toBe('300000000000003')
            ->and($parsed[4])->toBe('115.00')
            ->and($parsed[5])->toBe('15.00');
    });
});
