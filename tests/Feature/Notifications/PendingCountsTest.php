<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\InvoiceReturnRequest;
use App\Models\PurchaseRequest;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** تاسك 196 — عدّادات الطوابير المعلّقة على القائمة الجانبية. */
describe('Sidebar pending counts', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);
        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->invoice = fn (InvoiceStatusEnum $status, ?Branch $branch = null) => ServiceInvoice::create([
            'invoice_number' => 'SINV-PC-'.fake()->unique()->numerify('#####'),
            'branch_id' => ($branch ?? $this->branch)->id,
            'user_id' => $this->employee->id,
            'subtotal' => 115, 'vat_pct' => 15, 'vat_amount' => 15, 'total_amount' => 115,
            'employee_commission' => 0,
            'status' => $status,
        ]);

        // فرعٌ آخر يحمل من كل طابورٍ واحداً — لا يدخل عدّاد هذا الفرع.
        $other = Branch::factory()->create();
        foreach ([$this->branch, $other] as $branch) {
            ($this->invoice)(InvoiceStatusEnum::DUE, $branch);

            $paid = ($this->invoice)(InvoiceStatusEnum::PAID, $branch);
            InvoiceReturnRequest::create(['service_invoice_id' => $paid->id, 'branch_id' => $branch->id, 'requested_by' => $this->employee->id, 'status' => 'pending']);
            $paid->pendingShipments()->create(['fee' => 23, 'requested_by' => $this->employee->id]);

            Expense::factory()->create(['branch_id' => $branch->id, 'requested_by' => $this->employee->id, 'accepted_at' => null, 'approved_at' => null]);
            PurchaseRequest::factory()->create(['branch_id' => $branch->id, 'requested_by' => $this->employee->id]);
        }

        // ما بُتّ فيه لا يُعدّ.
        $decided = ($this->invoice)(InvoiceStatusEnum::PAID);
        InvoiceReturnRequest::create(['service_invoice_id' => $decided->id, 'branch_id' => $this->branch->id, 'requested_by' => $this->employee->id, 'status' => 'rejected']);
        Expense::factory()->create(['branch_id' => $this->branch->id, 'requested_by' => $this->employee->id, 'accepted_at' => now()]);
        PurchaseRequest::factory()->approved()->create(['branch_id' => $this->branch->id, 'requested_by' => $this->employee->id]);
        // طلب توصيلٍ على فاتورةٍ أُرجعت لم يعد يُعتمد.
        ($this->invoice)(InvoiceStatusEnum::RETURNED)->pendingShipments()->create(['fee' => 23, 'requested_by' => $this->employee->id]);
    });

    it('counts each queue in the accountant\'s branch, without purchase requests they cannot decide', function () {
        $this->actingAs($this->accountant)->get(route('invoices.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingCounts.reviewQueue', 1)
                ->where('pendingCounts.returnRequests', 1)
                ->where('pendingCounts.shipmentRequests', 1)
                ->where('pendingCounts.expenseRequests', 1)
                ->missing('pendingCounts.purchaseRequests'));
    });

    it('adds purchase requests for the branch admin', function () {
        $this->actingAs($this->admin)->get(route('invoices.index'))
            ->assertInertia(fn (Assert $page) => $page->where('pendingCounts.purchaseRequests', 1));
    });

    it('gives employees and auditors no counters', function (Roles $role) {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole($role->value);

        $this->actingAs($user)->get(route('invoices.index'))
            ->assertInertia(fn (Assert $page) => $page->where('pendingCounts', []));
    })->with([Roles::EMPLOYEE, Roles::AUDITOR]);

    it('lands the shipment counter on the invoices awaiting a shipment approval', function () {
        $this->actingAs($this->accountant)->get(route('invoices.index', ['pending_shipments' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.status', InvoiceStatusEnum::PAID->value));
    });
});
