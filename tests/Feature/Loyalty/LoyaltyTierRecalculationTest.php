<?php

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

/**
 * تصحيحٌ لمرّة واحدة بعد تغيير قاعدة الفئات: الإنفاق يُقاس شاملاً الضريبة،
 * والفئة تتبعه صعوداً وهبوطاً. الأمر يعيد بناء الرقمين من الفواتير المدفوعة.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

    LoyaltyConfig::factory()->create([
        'branch_id' => $this->branch->id,
        'bronze_threshold' => 500,
        'silver_threshold' => 2000,
        'gold_threshold' => 5000,
    ]);
});

function tierCustomer(array $attrs = []): Customer
{
    return Customer::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'customer_type' => CustomerTypeEnum::Individual,
        'agent_id' => null,
        'points_balance' => 0,
        'cumulative_spend' => 0,
        'tier' => CustomerTierEnum::None,
    ], $attrs));
}

function tierProductInvoice(Customer $customer, float $total, array $attrs = []): ProductInvoice
{
    $vat = round($total - $total / 1.15, 2);

    return ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-TIER-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => test()->branch->id,
        'user_id' => test()->user->id,
        'customer_id' => $customer->id,
        'agent_id' => null,
        'status' => InvoiceStatusEnum::PAID,
        'subtotal' => round($total - $vat, 2),
        'vat_pct' => 15,
        'vat_amount' => $vat,
        'total_amount' => $total,
        'paid_at' => now(),
    ], $attrs));
}

function tierServiceInvoice(Customer $customer, float $total): ServiceInvoice
{
    $vat = round($total - $total / 1.15, 2);

    return ServiceInvoice::create([
        'invoice_number' => 'SINV-TIER-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => test()->branch->id,
        'user_id' => test()->user->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatusEnum::PAID,
        'subtotal' => round($total - $vat, 2),
        'vat_pct' => 15,
        'vat_amount' => $vat,
        'total_amount' => $total,
        'employee_commission' => 0,
        'paid_at' => now(),
    ]);
}

it('promotes a customer whose invoices now clear the threshold', function () {
    // 460 صافياً كانت دون حدّ 500، و529 شاملةً الضريبة تبلغه.
    $customer = tierCustomer(['cumulative_spend' => 460]);
    tierProductInvoice($customer, 529.00);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
        ->and((float) $customer->cumulative_spend)->toBe(529.00);
});

it('demotes a customer left in a tier their invoices never supported', function () {
    // عميلٌ ذهبيّ بفاتورة واحدة صغيرة — أثرُ قاعدة «الفئة لا تنزل أبداً».
    $customer = tierCustomer(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 6000]);
    tierProductInvoice($customer, 700.00);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
        ->and((float) $customer->cumulative_spend)->toBe(700.00);
});

it('subtracts refunds from the rebuilt spend', function () {
    $customer = tierCustomer();
    $invoice = tierProductInvoice($customer, 2500.00);

    Refund::factory()->create([
        'branch_id' => $this->branch->id,
        'user_id' => $this->user->id,
        'invoice_id' => $invoice->id,
        'invoice_type' => ProductInvoice::class,
        'amount' => 700.00,
    ]);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    // 2500 − 700 = 1800، دون حدّ الفضي (2000).
    expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
        ->and((float) $customer->cumulative_spend)->toBe(1800.00);
});

it('ignores invoices that are not paid', function () {
    $customer = tierCustomer();
    tierProductInvoice($customer, 3000.00, ['status' => InvoiceStatusEnum::RETURNED]);
    tierProductInvoice($customer, 600.00, ['status' => InvoiceStatusEnum::DUE]);
    tierProductInvoice($customer, 550.00);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
        ->and((float) $customer->cumulative_spend)->toBe(550.00);
});

it('ignores agent invoices, matching the earning rule', function () {
    $agent = User::factory()->create(['branch_id' => $this->branch->id]);
    $customer = tierCustomer();

    tierProductInvoice($customer, 4000.00, ['agent_id' => $agent->id]);
    tierProductInvoice($customer, 550.00);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
        ->and((float) $customer->cumulative_spend)->toBe(550.00);
});

it('sums both invoice types', function () {
    $customer = tierCustomer();
    tierProductInvoice($customer, 1200.00);

    tierServiceInvoice($customer, 900.00);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    expect((float) $customer->refresh()->cumulative_spend)->toBe(2100.00)
        ->and($customer->tier)->toBe(CustomerTierEnum::Silver);
});

it('leaves corporate and agent-linked customers alone', function () {
    $corporate = tierCustomer([
        'customer_type' => CustomerTypeEnum::Corporate,
        'tier' => CustomerTierEnum::Gold,
        'cumulative_spend' => 9000,
    ]);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    expect($corporate->refresh()->tier)->toBe(CustomerTierEnum::Gold)
        ->and((float) $corporate->cumulative_spend)->toBe(9000.00);
});

it('skips branches whose loyalty program is switched off', function () {
    LoyaltyConfig::query()->where('branch_id', $this->branch->id)->update(['is_active' => false]);

    $customer = tierCustomer(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 9000]);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Gold);
});

it('writes nothing on a dry run', function () {
    $customer = tierCustomer(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 9000]);
    tierProductInvoice($customer, 550.00);

    artisan('loyalty:recalculate-tiers --dry-run')->assertSuccessful();

    expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Gold)
        ->and((float) $customer->cumulative_spend)->toBe(9000.00);
});

it('confines itself to one branch when asked', function () {
    $otherBranch = Branch::factory()->create();
    LoyaltyConfig::factory()->create(['branch_id' => $otherBranch->id]);

    $mine = tierCustomer(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 9000]);
    $theirs = Customer::factory()->create([
        'branch_id' => $otherBranch->id,
        'customer_type' => CustomerTypeEnum::Individual,
        'agent_id' => null,
        'tier' => CustomerTierEnum::Gold,
        'cumulative_spend' => 9000,
    ]);

    artisan("loyalty:recalculate-tiers --branch={$this->branch->id}")->assertSuccessful();

    expect($mine->refresh()->tier)->toBe(CustomerTierEnum::None)
        ->and($theirs->refresh()->tier)->toBe(CustomerTierEnum::Gold);
});

it('records every change in the customer activity log', function () {
    $customer = tierCustomer(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 9000]);
    tierProductInvoice($customer, 550.00);

    artisan('loyalty:recalculate-tiers')->assertSuccessful();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'customers',
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'description' => 'إعادة احتساب مستوى الولاء',
    ]);
});
