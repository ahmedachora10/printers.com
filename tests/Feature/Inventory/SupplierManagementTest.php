<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Supplier management', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->admin);
    });

    it('creates a supplier scoped to the current branch', function () {
        $this->post(route('inventory.suppliers.store'), [
            'name' => 'مورد الورق',
            'phone' => '0500000000',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', [
            'name' => 'مورد الورق',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('updates a supplier', function () {
        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

        $this->put(route('inventory.suppliers.update', $supplier), [
            'name' => 'اسم محدث',
        ])->assertRedirect();

        expect($supplier->refresh()->name)->toBe('اسم محدث');
    });

    it('toggles supplier status', function () {
        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

        $this->patch(route('inventory.suppliers.toggle-status', $supplier))->assertRedirect();

        expect($supplier->refresh()->is_active)->toBeFalse();
    });

    it('deletes a supplier with no purchase orders', function () {
        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

        $this->delete(route('inventory.suppliers.destroy', $supplier))->assertRedirect();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    });

    it('blocks deletion of a supplier linked to a purchase order', function () {
        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
        PurchaseOrder::factory()->create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'ordered_by' => $this->admin->id,
        ]);

        $this->delete(route('inventory.suppliers.destroy', $supplier))
            ->assertSessionHasErrors('supplier');

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'deleted_at' => null]);
    });

    it('only lists suppliers from the current branch', function () {
        Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'فرعنا']);
        $otherBranch = Branch::factory()->create();
        Supplier::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'فرع آخر']);

        $this->get(route('inventory.suppliers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inventory/suppliers/index')
                ->has('items.data', 1));
    });

    it('forbids employees from managing suppliers', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->post(route('inventory.suppliers.store'), ['name' => 'x'])->assertForbidden();
    });

    it('lets an accountant view but not create suppliers', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('inventory.suppliers.index'))->assertOk();
        $this->post(route('inventory.suppliers.store'), ['name' => 'x'])->assertForbidden();
    });
});
