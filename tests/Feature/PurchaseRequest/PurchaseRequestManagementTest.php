<?php

use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\PurchaseRequestDecidedNotification;
use App\Notifications\PurchaseRequestSubmittedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

describe('Internal purchase requests', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory(),
            'unit_id' => ProductUnit::factory(),
            'cost_price' => 12,
            'current_stock' => 0,
        ]);
    });

    $submit = function (?array $lines = null): PurchaseRequest {
        $lines ??= [['product_id' => test()->product->id, 'qty' => 5, 'estimated_unit_cost' => 10]];

        test()->post(route('purchase-requests.store'), ['lines' => $lines])->assertRedirect();

        return PurchaseRequest::latest('id')->first();
    };

    /**
     * تاسك 68: approval settles each line — its inventory product, the approved
     * quantity and its unit cost — because it writes a stock movement per line.
     * Unless a test says otherwise, every line keeps the product, quantity and
     * estimate it was raised with, and a free-text line falls back to the
     * branch's default product.
     */
    $approvalPayload = function (PurchaseRequest $request, array $overrides = []): array {
        return ['lines' => $request->lines->map(fn ($line) => [
            'id' => $line->id,
            'product_id' => $overrides[$line->id]['product_id'] ?? $line->product_id ?? test()->product->id,
            'qty' => $overrides[$line->id]['qty'] ?? (float) $line->qty,
            'unit_cost' => $overrides[$line->id]['unit_cost'] ?? (float) ($line->estimated_unit_cost ?? 0),
        ])->all()];
    };

    it('lets an employee raise a request for their own branch and notifies the branch admin and accountant', function () use ($submit) {
        Notification::fake();
        $this->actingAs($this->employee);

        $request = $submit([
            ['product_id' => $this->product->id, 'qty' => 3, 'estimated_unit_cost' => 20],
            ['item_name' => 'ورق لاصق مقاس خاص', 'qty' => 2],
        ]);

        expect($request->branch_id)->toBe($this->branch->id);
        expect($request->requested_by)->toBe($this->employee->id);
        expect($request->status)->toBe(PurchaseRequestStatusEnum::PENDING);
        expect($request->lines)->toHaveCount(2);
        // A catalogued line takes the product's name; a free-text one keeps
        // what was typed and stays unlinked.
        expect($request->lines->first()->item_name)->toBe($this->product->name);
        expect($request->lines->last()->product_id)->toBeNull();
        expect($request->estimatedTotal())->toBe(60.0);

        Notification::assertSentTo([$this->admin, $this->accountant], PurchaseRequestSubmittedNotification::class);
    });

    it('shows an employee only their own requests while the branch admin sees them all', function () use ($submit) {
        $this->actingAs($this->employee);
        $submit();

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($other);
        $submit();

        $this->actingAs($this->employee)
            ->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inventory/purchase-requests/index')
                ->has('items.data', 1));

        $this->actingAs($this->admin)
            ->get(route('purchase-requests.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 2));
    });

    it('forbids an employee from deciding on a request', function () use ($submit) {
        $this->actingAs($this->employee);
        $request = $submit();

        $this->patch(route('purchase-requests.approve', $request))->assertForbidden();
        $this->patch(route('purchase-requests.reject', $request), ['decision_reason' => 'لا'])->assertForbidden();
    });

    it('lets the branch admin approve a request and notifies the requester', function () use ($submit, $approvalPayload) {
        $this->actingAs($this->employee);
        $request = $submit();

        Notification::fake();
        $this->actingAs($this->admin)
            ->patch(route('purchase-requests.approve', $request), $approvalPayload($request))
            ->assertRedirect();

        $request->refresh();
        expect($request->status)->toBe(PurchaseRequestStatusEnum::APPROVED);
        expect($request->decided_by)->toBe($this->admin->id);
        expect($request->decided_at)->not->toBeNull();

        Notification::assertSentTo($this->employee, PurchaseRequestDecidedNotification::class);
    });

    it('feeds the branch stock with one movement per line at the cost the approver settled', function () use ($submit, $approvalPayload) {
        $sqmProduct = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory(),
            'unit_id' => ProductUnit::factory(),
            'is_sqm' => true,
            'current_stock' => 0,
        ]);

        $this->actingAs($this->employee);
        $request = $submit([
            ['product_id' => $this->product->id, 'qty' => 3, 'estimated_unit_cost' => 10],
            // A free-text line the approver links to a product on the spot.
            ['item_name' => 'فينيل بالمتر', 'qty' => 7.1, 'is_sqm' => true],
        ]);

        $freeLine = $request->lines->last();

        $this->actingAs($this->admin)
            ->patch(route('purchase-requests.approve', $request), $approvalPayload($request, [
                // The approver overrides the estimate the requester typed.
                $request->lines->first()->id => ['product_id' => $this->product->id, 'unit_cost' => 14.5],
                $freeLine->id => ['product_id' => $sqmProduct->id, 'unit_cost' => 30],
            ]))
            ->assertRedirect();

        $request->refresh();
        expect($request->stock_fed_at)->not->toBeNull();

        $movements = StockMovement::where('reference_type', PurchaseRequest::class)
            ->where('reference_id', $request->id)
            ->get();

        expect($movements)->toHaveCount(2);
        expect($movements->every(fn ($m) => $m->type === StockMovementTypeEnum::PURCHASE_IN))->toBeTrue();

        expect($this->product->refresh()->current_stock)->toEqual(3);
        // The decimal quantity survives all the way into the ledger.
        expect($sqmProduct->refresh()->current_stock)->toEqual(7.10);

        expect((float) $movements->firstWhere('product_id', $this->product->id)->unit_cost)->toBe(14.5);
        expect((float) $movements->firstWhere('product_id', $sqmProduct->id)->unit_cost)->toBe(30.0);

        // The settled line now carries the approver's product, name and cost.
        $freeLine->refresh();
        expect($freeLine->product_id)->toBe($sqmProduct->id);
        expect($freeLine->item_name)->toBe($sqmProduct->name);
        expect($freeLine->is_sqm)->toBeTrue();
        expect((float) $freeLine->estimated_unit_cost)->toBe(30.0);
    });

    it('feeds the stock with the quantity the approver settled, not the one that was asked for', function () use ($submit, $approvalPayload) {
        $this->actingAs($this->employee);
        $request = $submit([['product_id' => $this->product->id, 'qty' => 10, 'estimated_unit_cost' => 10]]);
        $line = $request->lines->first();

        // The branch admin only approves part of what was asked for.
        $this->actingAs($this->admin)
            ->patch(route('purchase-requests.approve', $request), $approvalPayload($request, [
                $line->id => ['qty' => 4, 'unit_cost' => 12],
            ]))
            ->assertRedirect();

        // The approved quantity replaces the requested one — it is what the
        // movement carries, so it is what the line records.
        expect((float) $line->refresh()->qty)->toBe(4.0);
        expect($this->product->refresh()->current_stock)->toEqual(4);

        $movement = StockMovement::where('reference_type', PurchaseRequest::class)
            ->where('reference_id', $request->id)
            ->sole();

        expect((float) $movement->qty)->toBe(4.0);
        expect((float) $movement->unit_cost)->toBe(12.0);
    });

    it('refuses to approve a line with no product or no cost, and writes no movement', function () use ($submit) {
        $this->actingAs($this->employee);
        $request = $submit([['item_name' => 'حبر خاص', 'qty' => 2]]);
        $line = $request->lines->first();

        $this->actingAs($this->admin);

        $this->patch(route('purchase-requests.approve', $request), [
            'lines' => [['id' => $line->id, 'qty' => 2, 'unit_cost' => 5]],
        ])->assertSessionHasErrors('lines.0.product_id');

        $this->patch(route('purchase-requests.approve', $request), [
            'lines' => [['id' => $line->id, 'qty' => 2, 'product_id' => $this->product->id]],
        ])->assertSessionHasErrors('lines.0.unit_cost');

        // The quantity is what enters the stock, so a line can never be
        // approved with none of it.
        $this->patch(route('purchase-requests.approve', $request), [
            'lines' => [['id' => $line->id, 'product_id' => $this->product->id, 'qty' => 0, 'unit_cost' => 5]],
        ])->assertSessionHasErrors('lines.0.qty');

        expect($request->refresh()->status)->toBe(PurchaseRequestStatusEnum::PENDING);
        expect(StockMovement::count())->toBe(0);
    });

    it('refuses to feed a line with a product from another branch', function () use ($submit) {
        $otherProduct = Product::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'category_id' => ProductCategory::factory(),
            'unit_id' => ProductUnit::factory(),
        ]);

        $this->actingAs($this->employee);
        $request = $submit();

        $this->actingAs($this->admin)
            ->patch(route('purchase-requests.approve', $request), [
                'lines' => [['id' => $request->lines->first()->id, 'product_id' => $otherProduct->id, 'qty' => 5, 'unit_cost' => 5]],
            ])
            ->assertSessionHasErrors('lines.0.product_id');

        expect(StockMovement::count())->toBe(0);
    });

    it('never lets an approved request feed the stock twice', function () use ($submit, $approvalPayload) {
        $this->actingAs($this->employee);
        $request = $submit([['product_id' => $this->product->id, 'qty' => 4, 'estimated_unit_cost' => 10]]);

        $this->actingAs($this->admin);
        $payload = $approvalPayload($request);

        $this->patch(route('purchase-requests.approve', $request), $payload)->assertRedirect();
        // A second decision is refused, so the ledger keeps a single movement.
        $this->patch(route('purchase-requests.approve', $request), $payload)->assertSessionHasErrors('status');

        expect(StockMovement::count())->toBe(1);
        expect($this->product->refresh()->current_stock)->toEqual(4);

        // And the old purchase-order path is closed on a fed request, so the
        // quantity can never arrive a second time through receiving.
        expect($request->refresh()->canConvert())->toBeFalse();
        $this->post(route('purchase-requests.convert', $request))->assertSessionHasErrors('status');
        expect(PurchaseOrder::count())->toBe(0);
    });

    it('requires a reason to reject and refuses a second decision', function () use ($submit, $approvalPayload) {
        $this->actingAs($this->employee);
        $request = $submit();

        $this->actingAs($this->admin)
            ->patch(route('purchase-requests.reject', $request))
            ->assertSessionHasErrors('decision_reason');

        expect($request->refresh()->status)->toBe(PurchaseRequestStatusEnum::PENDING);

        $this->patch(route('purchase-requests.reject', $request), ['decision_reason' => 'الميزانية مستنفدة'])
            ->assertRedirect();

        $request->refresh();
        expect($request->status)->toBe(PurchaseRequestStatusEnum::REJECTED);
        expect($request->decision_reason)->toBe('الميزانية مستنفدة');

        // The decision is final: approving a rejected request is rejected too,
        // even with a payload that would otherwise be perfectly valid.
        $this->patch(route('purchase-requests.approve', $request), $approvalPayload($request))
            ->assertSessionHasErrors('status');
        expect($request->refresh()->status)->toBe(PurchaseRequestStatusEnum::REJECTED);
    });

    it('converts a legacy approved request into exactly one draft purchase order', function () use ($submit) {
        $this->actingAs($this->employee);
        $request = $submit([
            ['product_id' => $this->product->id, 'qty' => 7, 'estimated_unit_cost' => 10],
            // Free-text items cannot be ordered; they are left out of the PO.
            ['item_name' => 'صنف غير مُعرَّف', 'qty' => 4],
        ]);

        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

        // Approved before تاسك 68: no seal, no movements, its free-text line
        // never linked. Only such a request still converts.
        $request->update([
            'status' => PurchaseRequestStatusEnum::APPROVED,
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('purchase-requests.convert', $request), ['supplier_id' => $supplier->id])
            ->assertRedirect();

        $request->refresh();
        $po = PurchaseOrder::latest('id')->first();

        expect($request->status)->toBe(PurchaseRequestStatusEnum::CONVERTED);
        expect($request->purchase_order_id)->toBe($po->id);
        expect($po->status)->toBe(PurchaseOrderStatusEnum::DRAFT);
        expect($po->branch_id)->toBe($this->branch->id);
        expect($po->supplier_id)->toBe($supplier->id);
        expect($po->lines)->toHaveCount(1);
        expect($po->lines->first()->ordered_qty)->toEqual(7);
        expect((float) $po->lines->first()->subtotal)->toBe(70.0);
        // Receiving stays in M29 — conversion itself writes no stock movement,
        // so the product only carries what approval fed it.
        expect(StockMovement::where('reference_type', PurchaseOrder::class)->count())->toBe(0);

        // Converting again is refused, so no second purchase order is created.
        $this->post(route('purchase-requests.convert', $request))->assertSessionHasErrors('status');
        expect(PurchaseOrder::count())->toBe(1);
    });

    it('refuses to convert a legacy approved request that has no catalogued items', function () use ($submit) {
        $this->actingAs($this->accountant);
        $request = $submit([['item_name' => 'حبر خاص', 'qty' => 1]]);

        // Approved before تاسك 68 — approved, unlinked and unfed.
        $request->update(['status' => PurchaseRequestStatusEnum::APPROVED, 'decided_by' => $this->admin->id, 'decided_at' => now()]);

        $this->actingAs($this->admin)
            ->post(route('purchase-requests.convert', $request))
            ->assertSessionHasErrors('lines');

        expect($request->refresh()->status)->toBe(PurchaseRequestStatusEnum::APPROVED);
        expect(PurchaseOrder::count())->toBe(0);
    });

    it('refuses to convert a request that was never approved', function () use ($submit) {
        $this->actingAs($this->employee);
        $request = $submit();

        $this->actingAs($this->admin)
            ->post(route('purchase-requests.convert', $request))
            ->assertSessionHasErrors('status');

        expect(PurchaseOrder::count())->toBe(0);
    });

    it('requires at least one line and a name for free-text items', function () {
        $this->actingAs($this->employee);

        $this->post(route('purchase-requests.store'), ['lines' => []])
            ->assertSessionHasErrors('lines');

        $this->post(route('purchase-requests.store'), ['lines' => [['qty' => 2]]])
            ->assertSessionHasErrors('lines.0.item_name');

        expect(PurchaseRequest::count())->toBe(0);
    });

    it('takes the quantity in the unit the product is defined with and keeps its decimals', function () use ($submit, $approvalPayload) {
        $sqmProduct = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory(),
            'unit_id' => ProductUnit::factory(),
            'is_sqm' => true,
            'cost_price' => 20,
        ]);

        $this->actingAs($this->employee);
        $request = $submit([
            ['product_id' => $sqmProduct->id, 'qty' => 7.1, 'estimated_unit_cost' => 20],
            ['product_id' => $this->product->id, 'qty' => 2, 'estimated_unit_cost' => 10],
            // A free-text item has no product to read the unit off, so the
            // requester says what they are counting.
            ['item_name' => 'فينيل بالمتر', 'qty' => 3.5, 'is_sqm' => true],
        ]);

        [$sqmLine, $pieceLine, $customLine] = $request->lines->all();

        expect((float) $sqmLine->qty)->toBe(7.10);
        expect($sqmLine->is_sqm)->toBeTrue();
        expect((float) $pieceLine->qty)->toBe(2.0);
        // The unit is pinned from the product, never read live afterwards.
        expect($pieceLine->is_sqm)->toBeFalse();
        expect((float) $customLine->qty)->toBe(3.5);
        expect($customLine->is_sqm)->toBeTrue();
        expect($request->estimatedTotal())->toBe(162.0);

        // A purchase order has accepted decimals since تاسك 51, so conversion
        // passes the quantity straight through.
        $this->actingAs($this->admin)
            ->patch(route('purchase-requests.approve', $request), $approvalPayload($request))
            ->assertRedirect();

        $request->refresh()->update(['stock_fed_at' => null]);
        $this->post(route('purchase-requests.convert', $request))->assertRedirect();

        expect(PurchaseOrder::latest('id')->first()->lines->firstWhere('product_id', $sqmProduct->id)->ordered_qty)
            ->toEqual(7.10);
    });

    it('refuses a zero or negative quantity', function () {
        $this->actingAs($this->employee);

        $this->post(route('purchase-requests.store'), [
            'lines' => [['product_id' => $this->product->id, 'qty' => 0]],
        ])->assertSessionHasErrors('lines.0.qty');

        $this->post(route('purchase-requests.store'), [
            'lines' => [['product_id' => $this->product->id, 'qty' => -3]],
        ])->assertSessionHasErrors('lines.0.qty');

        expect(PurchaseRequest::count())->toBe(0);
    });

    it('keeps a request invisible to a branch admin of another branch', function () use ($submit) {
        $this->actingAs($this->employee);
        $request = $submit();

        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $otherBranch = Branch::factory()->create(['owner_id' => $otherAdmin->id]);
        $otherAdmin->update(['branch_id' => $otherBranch->id]);

        $this->actingAs($otherAdmin)
            ->get(route('purchase-requests.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 0));

        $this->patch(route('purchase-requests.approve', $request))->assertForbidden();
    });

    it('blocks the agent portal from the module entirely', function () {
        $agent = User::factory()->create(['branch_id' => $this->branch->id]);
        $agent->addRole(Roles::AGENT->value);

        $this->actingAs($agent)->get(route('purchase-requests.index'))->assertForbidden();
    });
});
