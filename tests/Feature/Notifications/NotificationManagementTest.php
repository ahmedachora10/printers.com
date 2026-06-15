<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Database\Seeders\RolesAndPermissionsSeeder;

function makeProduct(Branch $branch): Product
{
    return Product::factory()->create([
        'branch_id' => $branch->id,
        'category_id' => ProductCategory::factory()->create()->id,
        'unit_id' => ProductUnit::factory()->create()->id,
    ]);
}

describe('Notifications management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);

        $this->other = User::factory()->create();
        $this->other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->admin);
    });

    it('lists only the authenticated user notifications', function () {
        $this->admin->notify(new LowStockNotification(makeProduct($this->branch), 2));
        $this->other->notify(new LowStockNotification(makeProduct($this->branch), 1));

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('notifications/index')
                ->has('items.data', 1)
                ->where('unreadCount', 1));
    });

    it('marks a single notification as read', function () {
        $this->admin->notify(new LowStockNotification(makeProduct($this->branch), 2));
        $id = $this->admin->notifications()->first()->id;

        $this->patch(route('notifications.read', $id))->assertRedirect();

        expect($this->admin->fresh()->unreadNotifications()->count())->toBe(0);
    });

    it('marks all notifications as read', function () {
        $this->admin->notify(new LowStockNotification(makeProduct($this->branch), 2));
        $this->admin->notify(new LowStockNotification(makeProduct($this->branch), 1));

        $this->patch(route('notifications.read-all'))->assertRedirect();

        expect($this->admin->fresh()->unreadNotifications()->count())->toBe(0);
    });

    it('deletes a notification', function () {
        $this->admin->notify(new LowStockNotification(makeProduct($this->branch), 2));
        $id = $this->admin->notifications()->first()->id;

        $this->delete(route('notifications.destroy', $id))->assertRedirect();

        expect($this->admin->fresh()->notifications()->count())->toBe(0);
    });

    it('cannot read or delete another users notification', function () {
        $this->other->notify(new LowStockNotification(makeProduct($this->branch), 2));
        $id = $this->other->notifications()->first()->id;

        $this->patch(route('notifications.read', $id))->assertNotFound();
        $this->delete(route('notifications.destroy', $id))->assertNotFound();

        expect($this->other->fresh()->notifications()->count())->toBe(1);
    });
});
