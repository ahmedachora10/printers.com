<?php

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Stock Movements', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->admin);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'current_stock' => 0,
        ]);
    });

    it('recomputes current_stock as the signed sum of the ledger', function () {
        $action = app(RecordStockMovementAction::class);

        $action->handle($this->product, StockMovementTypeEnum::OPENING_STOCK, 100);
        expect($this->product->refresh()->current_stock)->toBe(100);

        $action->handle($this->product, StockMovementTypeEnum::SALE_OUT, 30);
        expect($this->product->refresh()->current_stock)->toBe(70);

        $action->handle($this->product, StockMovementTypeEnum::RETURN_IN, 5);
        expect($this->product->refresh()->current_stock)->toBe(75);
    });

    it('stores outbound quantities as negative and inbound as positive', function () {
        $action = app(RecordStockMovementAction::class);

        $in = $action->handle($this->product, StockMovementTypeEnum::PURCHASE_IN, 40);
        $out = $action->handle($this->product, StockMovementTypeEnum::ADJUSTMENT_OUT, 10);

        expect($in->qty)->toBe(40)
            ->and($out->qty)->toBe(-10);
    });

    it('defaults created_by to the authenticated user', function () {
        $movement = app(RecordStockMovementAction::class)
            ->handle($this->product, StockMovementTypeEnum::OPENING_STOCK, 10);

        expect($movement->created_by)->toBe($this->admin->id);
    });

    it('forbids updating a stock movement', function () {
        $movement = StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);

        expect(fn () => $movement->update(['qty' => 999]))
            ->toThrow(RuntimeException::class);
    });

    it('forbids deleting a stock movement', function () {
        $movement = StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);

        expect(fn () => $movement->delete())
            ->toThrow(RuntimeException::class);
    });

    it('does not maintain an updated_at column', function () {
        $movement = StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);

        expect($movement->updated_at)->toBeNull();
    });
});

describe('Stock Movement Controller', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->admin);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'current_stock' => 0,
        ]);
    });

    // ── RECORD ─────────────────────────────────────────────────────

    it('records opening stock and updates current_stock', function () {
        $this->post(route('inventory.stock-movements.store'), [
            'product_id' => $this->product->id,
            'type' => 'opening_stock',
            'qty' => 50,
        ])->assertRedirect(route('inventory.products.index'));

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'type' => 'opening_stock',
            'qty' => 50,
        ]);

        expect($this->product->fresh()->current_stock)->toBe(50);
    });

    it('increases stock with adjustment_in', function () {
        StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 10,
        ]);

        $this->post(route('inventory.stock-movements.store'), [
            'product_id' => $this->product->id,
            'type' => 'adjustment_in',
            'qty' => 5,
        ])->assertRedirect();

        expect($this->product->fresh()->current_stock)->toBe(15);
    });

    it('decreases stock with adjustment_out', function () {
        StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 20,
        ]);

        $this->post(route('inventory.stock-movements.store'), [
            'product_id' => $this->product->id,
            'type' => 'adjustment_out',
            'qty' => 8,
        ])->assertRedirect();

        expect($this->product->fresh()->current_stock)->toBe(12);
    });

    // ── VALIDATION ─────────────────────────────────────────────────

    it('rejects an adjustment_out exceeding current stock', function () {
        StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 5,
        ]);

        $this->post(route('inventory.stock-movements.store'), [
            'product_id' => $this->product->id,
            'type' => 'adjustment_out',
            'qty' => 1000,
        ])->assertSessionHasErrors(['qty']);

        expect(StockMovement::where('product_id', $this->product->id)->where('type', 'adjustment_out')->count())->toBe(0);
    });

    it('rejects a system-only movement type', function () {
        $this->post(route('inventory.stock-movements.store'), [
            'product_id' => $this->product->id,
            'type' => 'sale_out',
            'qty' => 1,
        ])->assertSessionHasErrors(['type']);
    });

    it('requires product, type and qty', function () {
        $this->post(route('inventory.stock-movements.store'), [])
            ->assertSessionHasErrors(['product_id', 'type', 'qty']);
    });

    // ── LEDGER VIEW ────────────────────────────────────────────────

    it('shows the ledger to branch-admin', function () {
        $this->get(route('inventory.stock-movements.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('inventory/stock-movements/index'));
    });

    it('filters the ledger by product and type', function () {
        $other = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]);

        StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 10,
        ]);
        StockMovement::factory()->create([
            'product_id' => $other->id,
            'branch_id' => $this->branch->id,
            'type' => 'adjustment_in',
            'qty' => 3,
        ]);

        $this->get(route('inventory.stock-movements.index', ['product_id' => $this->product->id]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1));

        $this->get(route('inventory.stock-movements.index', ['type' => 'adjustment_in']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1));
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('lets accountant view the ledger but not record movements', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('inventory.stock-movements.index'))->assertOk();

        $this->post(route('inventory.stock-movements.store'), [
            'product_id' => $this->product->id,
            'type' => 'adjustment_in',
            'qty' => 1,
        ])->assertForbidden();
    });

    it('prevents employee from viewing or recording', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->get(route('inventory.stock-movements.index'))->assertForbidden();

        $this->post(route('inventory.stock-movements.store'), [
            'product_id' => $this->product->id,
            'type' => 'adjustment_in',
            'qty' => 1,
        ])->assertForbidden();
    });
});
