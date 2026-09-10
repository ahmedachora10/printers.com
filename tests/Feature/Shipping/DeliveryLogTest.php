<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryProvider;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** فاتورة خدمات عليها توصيل، بتاريخٍ ومزوّدٍ وقيمة. */
function deliveredInvoice(
    Branch $branch,
    User $user,
    ?DeliveryProvider $provider = null,
    float $fee = 20,
    ?string $createdAt = null,
    InvoiceStatusEnum $status = InvoiceStatusEnum::PAID,
): ServiceInvoice {
    $provider ??= DeliveryProvider::factory()->create(['branch_id' => $branch->id]);

    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-'.fake()->unique()->numerify('######'),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'shipping_fee' => $fee,
        'shipping_provider_id' => $provider->id,
        'shipping_address' => 'حي النرجس، مكتب 12',
        'vat_pct' => 15,
        'vat_amount' => 15.65,
        'total_amount' => round(100 + $fee, 2),
        'employee_commission' => 0,
        'status' => $status,
        'paid_at' => $status === InvoiceStatusEnum::PAID ? now() : null,
    ]);

    if ($createdAt !== null) {
        $invoice->forceFill(['created_at' => $createdAt])->save();
    }

    return $invoice->fresh();
}

describe('Delivery log', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id, 'vat_rate_override' => 15]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->driver = DeliveryProvider::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'أبو محمد',
            'phone' => '0509998887',
        ]);

        $this->actingAs($this->admin);
    });

    it('groups today deliveries by driver', function () {
        deliveredInvoice($this->branch, $this->admin, $this->driver, 20);
        deliveredInvoice($this->branch, $this->admin, $this->driver, 30);

        $other = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id, 'name' => 'شركة ثريا']);
        deliveredInvoice($this->branch, $this->admin, $other, 15);

        $this->get(route('shipping.deliveries'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shipping/deliveries')
                ->where('totals.deliveries', 3)
                ->where('totals.providers', 2)
                ->where('totals.fees', 65)
                // مرتَّبٌ بعدد الرحلات: صاحب الأكثر أولاً.
                ->where('byProvider.0.providerName', 'أبو محمد')
                ->where('byProvider.0.deliveries', 2)
                ->where('byProvider.0.fees', 50)
                ->where('byProvider.1.deliveries', 1));
    });

    it('opens on today and leaves yesterday out', function () {
        deliveredInvoice($this->branch, $this->admin, $this->driver, 20);
        deliveredInvoice($this->branch, $this->admin, $this->driver, 99, now()->subDay()->toDateTimeString());

        $this->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 1)->where('totals.fees', 20));

        // ويتّسع المدى بالفلتر فيظهر الأمس.
        $this->get(route('shipping.deliveries', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 2)->where('totals.fees', 119));
    });

    it('ignores invoices with no delivery at all', function () {
        ServiceInvoice::create([
            'invoice_number' => 'SINV-000999',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 13.04,
            'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::PAID,
        ]);

        $this->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 0)->has('byProvider', 0));
    });

    it('drops a cancelled or returned order — no trip to follow', function () {
        deliveredInvoice($this->branch, $this->admin, $this->driver, 20, null, InvoiceStatusEnum::CANCELLED);
        deliveredInvoice($this->branch, $this->admin, $this->driver, 30, null, InvoiceStatusEnum::RETURNED);
        deliveredInvoice($this->branch, $this->admin, $this->driver, 40);

        $this->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 1)->where('totals.fees', 40));
    });

    it('counts a free delivery as a trip', function () {
        deliveredInvoice($this->branch, $this->admin, $this->driver, 0);

        // التوصيل المجّاني رحلةٌ تُتابَع وإن لم يُقبض عليها شيء.
        $this->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 1)->where('totals.fees', 0));
    });

    it('filters by one driver', function () {
        deliveredInvoice($this->branch, $this->admin, $this->driver, 20);
        $other = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id]);
        deliveredInvoice($this->branch, $this->admin, $other, 50);

        $this->get(route('shipping.deliveries', ['provider' => $this->driver->id]))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 1)->where('totals.fees', 20));
    });

    it('carries the address and the customer onto each row', function () {
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'full_name' => 'خالد العتيبي',
            'phone' => '0501112223',
        ]);
        $invoice = deliveredInvoice($this->branch, $this->admin, $this->driver, 20);
        $invoice->update(['customer_id' => $customer->id]);

        $this->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page
                ->where('deliveries.data.0.customerName', 'خالد العتيبي')
                ->where('deliveries.data.0.customerPhone', '0501112223')
                ->where('deliveries.data.0.address', 'حي النرجس، مكتب 12')
                ->where('deliveries.data.0.providerName', 'أبو محمد'));
    });

    it('keeps a branch admin to their own branch', function () {
        deliveredInvoice($this->branch, $this->admin, $this->driver, 20);

        $foreignBranch = Branch::factory()->create();
        $foreignUser = User::factory()->create(['branch_id' => $foreignBranch->id]);
        deliveredInvoice($foreignBranch, $foreignUser, null, 500);

        $this->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 1)->where('totals.fees', 20));
    });

    it('shows a super admin every branch until one is picked', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        deliveredInvoice($this->branch, $this->admin, $this->driver, 20);
        $foreignBranch = Branch::factory()->create();
        $foreignUser = User::factory()->create(['branch_id' => $foreignBranch->id]);
        deliveredInvoice($foreignBranch, $foreignUser, null, 50);

        $this->actingAs($superAdmin)
            ->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 2)->where('totals.fees', 70));

        $this->actingAs($superAdmin)
            ->get(route('shipping.deliveries', ['branch' => $this->branch->id]))
            ->assertInertia(fn ($page) => $page->where('totals.deliveries', 1)->where('totals.fees', 20));
    });

    it('blocks an employee from the delivery log', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('shipping.deliveries'))
            ->assertForbidden();
    });
});
