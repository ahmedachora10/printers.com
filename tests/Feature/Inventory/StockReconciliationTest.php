<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\StockReconciliation;
use App\Models\StockReconciliationLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Stock reconciliation', function () {
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
        ]);

        StockMovement::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementTypeEnum::OPENING_STOCK,
            'qty' => 10,
            'created_by' => $this->admin->id,
        ]);
    });

    $startReconciliation = function (): StockReconciliation {
        test()->post(route('inventory.stock-reconciliations.store'))->assertRedirect();

        return StockReconciliation::latest('id')->first();
    };

    it('starts a reconciliation snapshotting active branch products', function () use ($startReconciliation) {
        $inactive = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'is_active' => false,
        ]);

        $reconciliation = $startReconciliation();

        expect($reconciliation->completed_at)->toBeNull();
        expect($reconciliation->lines)->toHaveCount(1);
        expect($reconciliation->lines->pluck('product_id'))->not->toContain($inactive->id);

        $line = $reconciliation->lines->first();
        expect($line->system_qty)->toBe(10);
        expect($line->physical_qty)->toBe(10);
        expect($line->variance)->toBe(0);
    });

    it('blocks starting a second reconciliation while one is in progress', function () use ($startReconciliation) {
        $startReconciliation();

        $this->post(route('inventory.stock-reconciliations.store'))
            ->assertSessionHasErrors('branch_id');

        expect(StockReconciliation::count())->toBe(1);
    });

    it('saves physical counts and computes variance against the snapshot', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();
        $line = $reconciliation->lines->first();

        $this->put(route('inventory.stock-reconciliations.counts', $reconciliation), [
            'counts' => [['line_id' => $line->id, 'physical_qty' => 7]],
        ])->assertRedirect();

        $line->refresh();
        expect($line->physical_qty)->toBe(7);
        expect($line->variance)->toBe(-3);
    });

    it('completes a reconciliation by posting adjustment movements for variances', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();
        $line = $reconciliation->lines->first();

        $this->put(route('inventory.stock-reconciliations.counts', $reconciliation), [
            'counts' => [['line_id' => $line->id, 'physical_qty' => 7]],
        ])->assertRedirect();

        $this->post(route('inventory.stock-reconciliations.complete', $reconciliation))->assertRedirect();

        expect($reconciliation->refresh()->completed_at)->not->toBeNull();

        $line->refresh();
        expect($line->movement_id)->not->toBeNull();

        $this->assertDatabaseHas('stock_movements', [
            'id' => $line->movement_id,
            'product_id' => $this->product->id,
            'type' => StockMovementTypeEnum::ADJUSTMENT_OUT->value,
            'qty' => -3,
            'reference_type' => StockReconciliation::class,
            'reference_id' => $reconciliation->id,
        ]);

        expect($this->product->refresh()->current_stock)->toBe(7);
    });

    it('posts an adjustment_in movement when the physical count exceeds the snapshot', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();
        $line = $reconciliation->lines->first();

        $this->put(route('inventory.stock-reconciliations.counts', $reconciliation), [
            'counts' => [['line_id' => $line->id, 'physical_qty' => 15]],
        ])->assertRedirect();

        $this->post(route('inventory.stock-reconciliations.complete', $reconciliation))->assertRedirect();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'type' => StockMovementTypeEnum::ADJUSTMENT_IN->value,
            'qty' => 5,
            'reference_type' => StockReconciliation::class,
            'reference_id' => $reconciliation->id,
        ]);

        expect($this->product->refresh()->current_stock)->toBe(15);
    });

    it('posts no movements for zero-variance lines', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();

        $this->post(route('inventory.stock-reconciliations.complete', $reconciliation))->assertRedirect();

        expect($reconciliation->refresh()->completed_at)->not->toBeNull();
        expect($reconciliation->lines->first()->movement_id)->toBeNull();
        expect(StockMovement::where('reference_type', StockReconciliation::class)->count())->toBe(0);
        expect($this->product->refresh()->current_stock)->toBe(10);
    });

    it('blocks editing counts or re-completing a completed reconciliation', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();
        $line = $reconciliation->lines->first();

        $this->post(route('inventory.stock-reconciliations.complete', $reconciliation))->assertRedirect();

        $this->put(route('inventory.stock-reconciliations.counts', $reconciliation), [
            'counts' => [['line_id' => $line->id, 'physical_qty' => 1]],
        ])->assertSessionHasErrors('counts');

        $this->post(route('inventory.stock-reconciliations.complete', $reconciliation))
            ->assertSessionHasErrors('reconciliation');

        expect($line->refresh()->physical_qty)->toBe(10);
    });

    it('rejects counts for lines belonging to another reconciliation', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();

        $otherProduct = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]);

        $otherLine = StockReconciliationLine::factory()->create([
            'reconciliation_id' => StockReconciliation::factory()->completed()->create([
                'branch_id' => $this->branch->id,
                'initiated_by' => $this->admin->id,
            ])->id,
            'product_id' => $otherProduct->id,
        ]);

        $this->put(route('inventory.stock-reconciliations.counts', $reconciliation), [
            'counts' => [['line_id' => $otherLine->id, 'physical_qty' => 5]],
        ])->assertSessionHasErrors('counts');
    });

    it('deletes an in-progress reconciliation but not a completed one', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();

        $this->delete(route('inventory.stock-reconciliations.destroy', $reconciliation))->assertRedirect();
        expect(StockReconciliation::count())->toBe(0);
        expect(StockReconciliationLine::count())->toBe(0);

        $completed = $startReconciliation();
        $this->post(route('inventory.stock-reconciliations.complete', $completed))->assertRedirect();

        $this->delete(route('inventory.stock-reconciliations.destroy', $completed))
            ->assertSessionHasErrors('reconciliation');
        expect(StockReconciliation::count())->toBe(1);
    });

    it('lets an accountant view but not start, edit or complete reconciliations', function () use ($startReconciliation) {
        $reconciliation = $startReconciliation();
        $line = $reconciliation->lines->first();

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('inventory.stock-reconciliations.index'))->assertOk();
        $this->get(route('inventory.stock-reconciliations.show', $reconciliation))->assertOk();

        $this->post(route('inventory.stock-reconciliations.store'))->assertForbidden();
        $this->put(route('inventory.stock-reconciliations.counts', $reconciliation), [
            'counts' => [['line_id' => $line->id, 'physical_qty' => 1]],
        ])->assertForbidden();
        $this->post(route('inventory.stock-reconciliations.complete', $reconciliation))->assertForbidden();
    });

    it('forbids employees from the reconciliation screens', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->get(route('inventory.stock-reconciliations.index'))->assertForbidden();
    });
});
