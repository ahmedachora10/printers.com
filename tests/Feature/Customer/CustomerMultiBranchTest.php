<?php

use App\Enums\CustomerTierEnum;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * العميل متعدد الفروع: صفٌّ لكل فرع يربطها الجوال. الهوية مشتركة، والمعاملات
 * والنقاط والمستوى والائتمان والمندوب خاصّة بالفرع، والسوبر أدمن وحده يرى الكل.
 */
function multiBranchAdmin(Branch $branch): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->addRole(Roles::BRANCH_ADMIN->value);
    $branch->update(['owner_id' => $user->id]);

    return $user;
}

describe('Customer across branches', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();
        $this->adminA = multiBranchAdmin($this->branchA);
        $this->adminB = multiBranchAdmin($this->branchB);

        $this->customerA = Customer::factory()->create([
            'branch_id' => $this->branchA->id,
            'full_name' => 'سارة الأحمد',
            'phone' => '0551234567',
            'email' => 'sara@example.com',
            'tax_number' => '300000000000003',
            'points_balance' => 900,
            'cumulative_spend' => 6000,
            'tier' => CustomerTierEnum::Gold,
            'credit_limit' => 1000,
            'notes' => 'ملاحظة فرع أ',
        ]);
    });

    it('links a walk-in in another branch with the shared identity and nothing else', function () {
        $template = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branchB->id, 'service_template_id' => $template->id,
            'base_commission_pct' => 10, 'max_discount_pct' => 20, 'is_tahazir' => false, 'is_active' => true,
        ]);
        $service = BranchService::where('branch_id', $this->branchB->id)->firstOrFail();

        $employee = User::factory()->create(['branch_id' => $this->branchB->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'walkin_name' => 'اسم مكتوب',
            'walkin_phone' => '+966 55 123 4567',
            'lines' => [['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 50, 'discount_pct' => 0]],
        ])->assertRedirect();

        $linked = Customer::where('branch_id', $this->branchB->id)->sole();

        expect(ServiceInvoice::sole()->customer_id)->toBe($linked->id)
            ->and($linked->only(Customer::IDENTITY_FIELDS))->toEqual($this->customerA->only(Customer::IDENTITY_FIELDS))
            ->and($linked->points_balance)->toBe(0)
            ->and($linked->tier)->toBe(CustomerTierEnum::None)
            ->and($linked->credit_limit)->toBeNull()
            ->and($linked->notes)->toBeNull();
    });

    it('fills only an empty name or tax number from what the cashier typed', function () {
        $walkIn = Customer::factory()->create([
            'branch_id' => $this->branchA->id, 'full_name' => Customer::WALK_IN_NAME, 'phone' => '0559999999', 'tax_number' => null,
        ]);

        $linked = Customer::resolveWalkIn($this->branchB->id, 'خالد', '0559999999', '311111111111113');

        expect($linked->full_name)->toBe('خالد')
            ->and($walkIn->fresh()->full_name)->toBe('خالد')
            ->and($walkIn->fresh()->tax_number)->toBe('311111111111113')
            ->and(Customer::resolveWalkIn($this->branchA->id, 'اسم آخر', '0559999999')->id)->toBe($walkIn->id)
            ->and($walkIn->fresh()->full_name)->toBe('خالد');
    });

    it('links on a full phone in the POS search but never by name', function () {
        $this->actingAs($this->adminB);

        $this->getJson(route('pos.customers.search', ['q' => 'سارة']))->assertOk()->assertJsonCount(0, 'data');

        $this->getJson(route('pos.customers.search', ['q' => '0551234567']))
            ->assertOk()
            ->assertJsonPath('data.0.fullName', 'سارة الأحمد')
            ->assertJsonPath('data.0.pointsBalance', 0);

        expect(Customer::where('phone', '0551234567')->count())->toBe(2);
    });

    it('shares identity edits across branches but keeps branch data', function () {
        $linked = Customer::resolveWalkIn($this->branchB->id, '', '0551234567');
        $linked->update(['points_balance' => 40, 'notes' => 'ملاحظة فرع ب']);

        $this->actingAs($this->adminA)->put(route('customers.update', $this->customerA), [
            'full_name' => 'سارة محمد',
            'phone' => '0557654321',
            'email' => 'new@example.com',
            'customer_type' => 'individual',
            'credit_limit' => 1000,
            'notes' => 'ملاحظة فرع أ',
        ])->assertSessionHasNoErrors();

        $linked->refresh();
        expect($linked->full_name)->toBe('سارة محمد')
            ->and($linked->phone)->toBe('0557654321')
            ->and($linked->email)->toBe('new@example.com')
            ->and($linked->points_balance)->toBe(40)
            ->and($linked->notes)->toBe('ملاحظة فرع ب');
    });

    it('rejects a phone that belongs to another customer in any branch', function () {
        Customer::factory()->create(['branch_id' => $this->branchB->id, 'phone' => '0500000001']);

        $this->actingAs($this->adminA)->put(route('customers.update', $this->customerA), [
            'full_name' => 'سارة',
            'phone' => '0500000001',
            'customer_type' => 'individual',
        ])->assertSessionHasErrors('phone');
    });

    it('creates a branch row with the registered identity from the customer screen', function () {
        $this->actingAs($this->adminB)->post(route('customers.store'), [
            'full_name' => 'اسم مختلف',
            'phone' => '0551234567',
            'customer_type' => 'individual',
            'credit_limit' => 200,
        ])->assertSessionHasNoErrors();

        $linked = Customer::where('branch_id', $this->branchB->id)->sole();
        expect($linked->full_name)->toBe('سارة الأحمد')
            ->and($linked->credit_limit)->toBe('200.00')
            ->and($linked->points_balance)->toBe(0);
    });

    it('keeps each branch out of the other branch rows and shows them to the super admin', function () {
        $linked = Customer::resolveWalkIn($this->branchB->id, '', '0551234567');

        $this->actingAs($this->adminB)->get(route('customers.show', $this->customerA))->assertForbidden();
        $this->actingAs($this->adminB)->get(route('customers.show', $linked))
            ->assertInertia(fn ($page) => $page->where('linkedCustomers', []));

        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)->get(route('customers.show', $this->customerA))
            ->assertInertia(fn ($page) => $page
                ->has('linkedCustomers', 1)
                ->where('linkedCustomers.0.id', $linked->id)
                ->where('linkedCustomers.0.branchName', $this->branchB->name));
    });

    it('offers and accepts merge candidates from the customer branch only', function () {
        $sameBranch = Customer::factory()->create(['branch_id' => $this->branchA->id]);
        $otherBranch = Customer::factory()->create(['branch_id' => $this->branchB->id]);

        $this->actingAs($this->adminA)->get(route('customers.show', $this->customerA))
            ->assertInertia(fn ($page) => $page->has('customers', 1)->where('customers.0.id', $sameBranch->id));

        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)
            ->post(route('customers.merge', $this->customerA), ['secondary_customer_id' => $otherBranch->id])
            ->assertSessionHasErrors('secondary_customer_id');
    });

    it('rejects a product invoice for a customer of another branch', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branchB->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $product = Product::factory()->create([
            'branch_id' => $this->branchB->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10, 'cost_price' => 6, 'current_stock' => 0,
        ]);
        StockMovement::factory()->create([
            'product_id' => $product->id, 'branch_id' => $this->branchB->id,
            'type' => StockMovementTypeEnum::OPENING_STOCK, 'qty' => 10, 'created_by' => $accountant->id,
        ]);

        $this->actingAs($accountant)->post(route('pos.product.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'paid',
            'customer_id' => $this->customerA->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0]],
        ])->assertSessionHasErrors('customer_id');
    });

    it('unifies the identity of existing duplicates in the migration', function () {
        // مكرَّرٌ قائمٌ قبل الربط: بلا أحداث فلا تُزامِنه الإضافة نفسها.
        $older = Customer::withoutEvents(fn () => Customer::factory()->create([
            'branch_id' => $this->branchB->id, 'phone' => '0551234567', 'full_name' => Customer::WALK_IN_NAME,
            'email' => null, 'points_balance' => 15,
        ]));
        // تعديلٌ أحدث لكنه «عميل عابر»: الاسم الحقيقي يغلب.
        Customer::query()->whereKey($older->id)->update(['updated_at' => now()->addDay()]);

        (require database_path('migrations/2026_11_03_120000_sync_customer_identity_across_branches.php'))->up();

        expect($older->fresh()->full_name)->toBe('سارة الأحمد')
            ->and($older->fresh()->email)->toBe('sara@example.com')
            ->and($older->fresh()->points_balance)->toBe(15);
    });
});
