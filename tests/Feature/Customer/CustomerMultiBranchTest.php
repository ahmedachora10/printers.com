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
 * عميلٌ واحد في فرعٍ أو أكثر (customer_branch). الهوية واحدة، وحساب كل فرع —
 * نقاطه ومستواه وإنفاقه وائتمانه ومندوبه وملاحظاته وفواتيره — لذلك الفرع وحده،
 * والسوبر أدمن وحده يرى الكل.
 */
function multiBranchAdmin(Branch $branch): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->addRole(Roles::BRANCH_ADMIN->value);
    $branch->update(['owner_id' => $user->id]);

    return $user;
}

function multiBranchInvoice(Customer $customer, Branch $branch, float $total): ServiceInvoice
{
    return ServiceInvoice::create([
        'invoice_number' => 'SINV-'.fake()->unique()->numerify('####'),
        'branch_id' => $branch->id,
        'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
        'customer_id' => $customer->id,
        'subtotal' => $total, 'vat_pct' => 15, 'vat_amount' => 0, 'total_amount' => $total,
        'employee_commission' => 0, 'status' => 'due',
    ]);
}

describe('One customer across branches', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();
        $this->adminA = multiBranchAdmin($this->branchA);
        $this->adminB = multiBranchAdmin($this->branchB);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branchA->id,
            'full_name' => 'سارة الأحمد',
            'phone' => '0551234567',
            'points_balance' => 900,
            'cumulative_spend' => 6000,
            'tier' => CustomerTierEnum::Gold,
            'credit_limit' => 1000,
            'notes' => 'ملاحظة فرع أ',
        ]);
    });

    it('links the same customer to a second branch on its first invoice there, with a fresh account', function () {
        $template = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branchB->id, 'service_template_id' => $template->id,
            'base_commission_pct' => 10, 'max_discount_pct' => 20, 'is_tahazir' => false, 'is_active' => true,
        ]);
        $employee = User::factory()->create(['branch_id' => $this->branchB->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'walkin_name' => 'اسم مكتوب',
            'walkin_phone' => '+966 55 123 4567',
            'lines' => [['branch_service_id' => BranchService::where('branch_id', $this->branchB->id)->value('id'), 'qty' => 1, 'unit_price' => 50, 'discount_pct' => 0]],
        ])->assertSessionHasNoErrors();

        $accountB = account($this->customer, $this->branchB->id);

        expect(Customer::count())->toBe(1)
            ->and(ServiceInvoice::sole()->customer_id)->toBe($this->customer->id)
            ->and($this->customer->fresh()->full_name)->toBe('سارة الأحمد')
            ->and($accountB->points_balance)->toBe(0)
            ->and($accountB->tier)->toBe(CustomerTierEnum::None)
            ->and($accountB->credit_limit)->toBeNull()
            ->and(account($this->customer, $this->branchA->id)->points_balance)->toBe(900);
    });

    it('finds a customer of another branch by full phone without linking it, and never by name', function () {
        $this->actingAs($this->adminB);

        $this->getJson(route('pos.customers.search', ['q' => 'سارة']))->assertOk()->assertJsonCount(0, 'data');

        $this->getJson(route('pos.customers.search', ['q' => '0551234567']))
            ->assertOk()
            ->assertJsonPath('data.0.fullName', 'سارة الأحمد')
            ->assertJsonPath('data.0.linked', false)
            ->assertJsonPath('data.0.pointsBalance', 0);

        expect($this->customer->accounts()->count())->toBe(1);
    });

    it('links an existing phone from the customer screen instead of creating a copy', function () {
        $this->actingAs($this->adminB)->post(route('customers.store'), [
            'full_name' => 'اسم مختلف',
            'phone' => '0551234567',
            'customer_type' => 'individual',
            'credit_limit' => 200,
        ])->assertSessionHasNoErrors();

        expect(Customer::count())->toBe(1)
            ->and($this->customer->fresh()->full_name)->toBe('سارة الأحمد')
            ->and(account($this->customer, $this->branchB->id)->credit_limit)->toBe('200.00')
            ->and(account($this->customer, $this->branchA->id)->credit_limit)->toBe('1000.00');

        // مرّةً ثانية في الفرع نفسه: مسجَّلٌ فيه أصلاً.
        $this->actingAs($this->adminB)->post(route('customers.store'), [
            'full_name' => 'سارة', 'phone' => '0551234567', 'customer_type' => 'individual',
        ])->assertSessionHasErrors('phone');
    });

    it('shares identity edits but keeps each branch fields to that branch', function () {
        $this->customer->attachTo($this->branchB->id, ['notes' => 'ملاحظة فرع ب']);

        $this->actingAs($this->adminA)->put(route('customers.update', $this->customer), [
            'full_name' => 'سارة محمد',
            'phone' => '0557654321',
            'customer_type' => 'individual',
            'credit_limit' => 1500,
            'notes' => 'ملاحظة أ معدّلة',
        ])->assertSessionHasNoErrors();

        expect($this->customer->fresh()->full_name)->toBe('سارة محمد')
            ->and(account($this->customer, $this->branchA->id)->credit_limit)->toBe('1500.00')
            ->and(account($this->customer, $this->branchB->id)->notes)->toBe('ملاحظة فرع ب')
            ->and(account($this->customer, $this->branchB->id)->credit_limit)->toBeNull();
    });

    it('shows each branch only its own invoices, points and stats', function () {
        $this->customer->attachTo($this->branchB->id, ['points_balance' => 40]);
        multiBranchInvoice($this->customer, $this->branchA, 300);
        multiBranchInvoice($this->customer, $this->branchB, 70);

        $this->actingAs($this->adminB)->get(route('customers.show', $this->customer))
            ->assertInertia(fn ($page) => $page
                ->where('customer.pointsBalance', 40)
                ->where('customer.creditLimit', null)
                ->missing('customer.branches')
                ->where('financialSummary.invoiceCount', 1)
                ->where('financialSummary.totalBilled', 70)
                ->has('invoiceHistory.data', 1));

        $this->actingAs($this->adminA)->get(route('customers.index'))
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.pointsBalance', 900)
                ->where("stats.{$this->customer->id}.invoiceCount", 1));
    });

    it('keeps a branch out of customers it does not have', function () {
        $this->actingAs($this->adminB)->get(route('customers.show', $this->customer))->assertForbidden();
        $this->actingAs($this->adminB)->get(route('customers.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 0));
    });

    it('lists the customer once for the super admin with every branch', function () {
        $this->customer->attachTo($this->branchB->id, ['points_balance' => 40]);
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)->get(route('customers.index'))
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.pointsBalance', 940)
                ->has('items.data.0.branches', 2));
    });

    it('unlinks from one branch only, and refuses while that branch has invoices', function () {
        $this->customer->attachTo($this->branchB->id);
        multiBranchInvoice($this->customer, $this->branchA, 100);

        $this->actingAs($this->adminA)->delete(route('customers.destroy', $this->customer))
            ->assertSessionHasErrors('customer');

        $this->actingAs($this->adminB)->delete(route('customers.destroy', $this->customer))
            ->assertSessionHasNoErrors();

        expect($this->customer->accounts()->pluck('branch_id')->all())->toBe([$this->branchA->id])
            ->and($this->customer->fresh()->trashed())->toBeFalse();
    });

    it('rejects a product invoice for a customer not linked to the branch', function () {
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
            'customer_id' => $this->customer->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0]],
        ])->assertSessionHasErrors('customer_id');
    });

    it('lets a branch admin merge only a customer that belongs to their branch alone', function () {
        $shared = Customer::factory()->create(['branch_id' => $this->branchA->id]);
        $shared->attachTo($this->branchB->id);
        $own = Customer::factory()->create(['branch_id' => $this->branchA->id, 'points_balance' => 10]);

        $this->actingAs($this->adminA)
            ->post(route('customers.merge', $this->customer), ['secondary_customer_id' => $shared->id])
            ->assertSessionHasErrors('secondary_customer_id');

        $this->actingAs($this->adminA)
            ->post(route('customers.merge', $this->customer), ['secondary_customer_id' => $own->id])
            ->assertSessionHasNoErrors();

        expect(account($this->customer)->points_balance)->toBe(910);
    });
});
