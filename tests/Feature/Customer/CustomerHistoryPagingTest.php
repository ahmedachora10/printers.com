<?php

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * جدولا صفحة العميل — سجلّ الفواتير وسجلّ النقاط — كانا مقصوصين بحدٍّ ثابت
 * (100 و50) بلا سبيل إلى ما وراءه. صارا مرقَّمين، كلٌّ باسم صفحته.
 */
function makeInvoices(string $table, int $count, string $prefix): void
{
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'invoice_number' => "{$prefix}-{$i}",
            'branch_id' => test()->branch->id,
            'customer_id' => test()->customer->id,
            'user_id' => test()->admin->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 13.04,
            'total_amount' => 100,
            'status' => 'paid',
            // ترتيب تنازلي مضمون: كلما كبر i تأخّر التاريخ.
            'created_at' => now()->subDays(100 - $i),
            'updated_at' => now(),
        ];
    }

    DB::table($table)->insert($rows);
}

describe('Customer history paging', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        actingAs($this->admin);

        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    });

    it('pages the invoice history across both tables', function () {
        makeInvoices('service_invoices', 8, 'SINV');
        makeInvoices('product_invoices', 7, 'INV');

        get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invoiceHistory.data', 10)
                ->where('invoiceHistory.meta.total', 15)
                ->where('invoiceHistory.meta.last_page', 2));

        get(route('customers.show', ['customer' => $this->customer, 'invoicePage' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invoiceHistory.data', 5)
                ->where('invoiceHistory.meta.current_page', 2));
    });

    // الاتحاد يفرز على المجموع لا على كل جدول وحده، فالنوعان يتداخلان بالتاريخ.
    it('interleaves both invoice types in date order', function () {
        makeInvoices('service_invoices', 6, 'SINV');
        makeInvoices('product_invoices', 6, 'INV');

        get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(function ($page) {
                $rows = $page->toArray()['props']['invoiceHistory']['data'];
                $dates = array_column($rows, 'created_at');
                $sorted = $dates;
                rsort($sorted);

                expect($dates)->toBe($sorted)
                    // لو فُرز كل جدول وحده لجاءت الصفحة الأولى من نوع واحد
                    ->and(array_unique(array_column($rows, 'type')))->toHaveCount(2);
            });
    });

    it('shows every invoice beyond the old hard limit of 100', function () {
        makeInvoices('service_invoices', 60, 'SINV');
        makeInvoices('product_invoices', 60, 'INV');

        get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoiceHistory.meta.total', 120)
                ->where('invoiceHistory.meta.last_page', 12));
    });

    it('excludes soft-deleted invoices from the union', function () {
        makeInvoices('service_invoices', 4, 'SINV');
        DB::table('service_invoices')->where('invoice_number', 'SINV-1')->update(['deleted_at' => now()]);

        get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoiceHistory.meta.total', 3));
    });

    it('pages the loyalty history on its own key', function () {
        LoyaltyTransaction::factory()->count(23)->create([
            'customer_id' => $this->customer->id,
            'type' => LoyaltyTransactionTypeEnum::Earn,
            'points' => 1,
            'balance_after' => 1,
        ]);

        get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('loyaltyHistory.data', 10)
                ->where('loyaltyHistory.meta.total', 23)
                ->where('loyaltyHistory.meta.last_page', 3));

        get(route('customers.show', ['customer' => $this->customer, 'loyaltyPage' => 3]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('loyaltyHistory.data', 3)
                ->where('loyaltyHistory.meta.current_page', 3));
    });

    // مفتاحان مختلفان: التنقّل في جدول لا يُرجع الآخر إلى أوّله.
    it('keeps the two tables on independent page keys', function () {
        makeInvoices('service_invoices', 15, 'SINV');
        LoyaltyTransaction::factory()->count(15)->create([
            'customer_id' => $this->customer->id,
            'type' => LoyaltyTransactionTypeEnum::Earn,
            'points' => 1,
            'balance_after' => 1,
        ]);

        get(route('customers.show', [
            'customer' => $this->customer,
            'invoicePage' => 2,
            'loyaltyPage' => 1,
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoiceHistory.meta.current_page', 2)
                ->where('loyaltyHistory.meta.current_page', 1)
                ->has('loyaltyHistory.data', 10));
    });

    it('serves empty meta for a customer with no history', function () {
        get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoiceHistory.meta.total', 0)
                ->where('loyaltyHistory.meta.total', 0)
                ->has('invoiceHistory.data', 0)
                ->has('loyaltyHistory.data', 0));
    });

    it('asks whether a table exists only once per request', function () {
        makeInvoices('service_invoices', 3, 'SINV');

        $schemaLookups = 0;
        DB::listen(function ($query) use (&$schemaLookups) {
            if (str_contains($query->sql, 'sqlite_master') || str_contains($query->sql, 'information_schema')) {
                $schemaLookups++;
            }
        });

        get(route('customers.show', $this->customer))->assertOk();

        // ثلاثة جداول تُفحص: الخدمات والمنتجات وحركات الولاء — مرة لكل منها.
        expect($schemaLookups)->toBe(3);
    });
});
