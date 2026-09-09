<?php

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryProvider;
use App\Models\LoyaltyConfig;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * فاتورة خدمات مسدَّدة: خدماتٌ بـ100 + توصيلٌ بـ20 = 120، لعميلٍ كسب عليها نقاطاً.
 */
function shippedPaidInvoice(Branch $branch, User $user, Customer $customer): ServiceInvoice
{
    $provider = DeliveryProvider::factory()->create(['branch_id' => $branch->id]);

    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-001-00001',
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'subtotal' => 100,
        'shipping_fee' => 20,
        'shipping_provider_id' => $provider->id,
        'vat_pct' => 15,
        // 120 ÷ 1.15 = 104.35 → الضريبة 15.65
        'vat_amount' => 15.65,
        'total_amount' => 120,
        'employee_commission' => 0,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
    ]);

    // ما أُضيف إلى إنفاق العميل هو مال الخدمات وحده: 100 لا 120.
    $customer->update(['cumulative_spend' => 100]);

    return $invoice->fresh();
}

describe('Refunding the shipping fee is the accountant decision', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id, 'vat_rate_override' => 15]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        LoyaltyConfig::updateOrCreate(
            ['branch_id' => $this->branch->id],
            ['earning_rate' => 1, 'is_active' => true, 'bronze_threshold' => 100000],
        );

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'agent_id' => null,
            'tier' => CustomerTierEnum::None,
        ]);

        $this->actingAs($this->admin);
    });

    it('records nothing against shipping unless the accountant asks', function () {
        $invoice = shippedPaidInvoice($this->branch, $this->admin, $this->customer);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'reason' => 'مرتجع جزئي',
        ])->assertRedirect();

        // الافتراضيّ ألّا تُردّ: الغالب أن السائق ذهب فعلاً.
        expect((float) Refund::firstOrFail()->shipping_refunded)->toEqual(0.00);
    });

    it('records the shipping share when the accountant ticks the box', function () {
        $invoice = shippedPaidInvoice($this->branch, $this->admin, $this->customer);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 120,
            'reason' => 'الطلب لم يُشحن أصلاً',
            'refund_shipping' => true,
        ])->assertRedirect();

        expect((float) Refund::firstOrFail()->shipping_refunded)->toEqual(20.00);
    });

    it('never returns the shipping twice across two partial refunds', function () {
        $invoice = shippedPaidInvoice($this->branch, $this->admin, $this->customer);

        foreach ([60, 60] as $amount) {
            $this->post(route('refunds.store'), [
                'source_type' => 'service',
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'reason' => 'مرتجع جزئي',
                'refund_shipping' => true,
            ])->assertRedirect();
        }

        // 20 مرّةً واحدة مهما تكرّر الطلب.
        expect((float) Refund::sum('shipping_refunded'))->toEqual(20.00);
    });

    it('caps the shipping share at the refund amount itself', function () {
        $invoice = shippedPaidInvoice($this->branch, $this->admin, $this->customer);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 5,
            'reason' => 'مرتجع صغير',
            'refund_shipping' => true,
        ])->assertRedirect();

        // الشحن جزءٌ من المبلغ لا إضافةٌ عليه، فلا يتجاوزه.
        expect((float) Refund::firstOrFail()->shipping_refunded)->toEqual(5.00);
    });

    it('rolls back only the services share of the customer spend', function () {
        $invoice = shippedPaidInvoice($this->branch, $this->admin, $this->customer);

        // اكتسابٌ قائم على الفاتورة — شرطُ صحّة الخصم من الإنفاق.
        $this->customer->loyaltyTransactions()->create([
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->getMorphClass(),
            'type' => 'earn',
            'points' => 86,
            'balance_after' => 86,
        ]);
        $this->customer->update(['points_balance' => 86]);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 120,
            'reason' => 'إرجاع كامل',
            'refund_shipping' => true,
        ])->assertRedirect();

        // أُضيف 100 إلى الإنفاق، فيُطرح 100: المرتجع 120 لكنّ عشرينه شحنٌ لم
        // يدخل الإنفاق أصلاً. ولولا الطرح لصار الإنفاق سالباً بعشرين.
        expect((float) $this->customer->refresh()->cumulative_spend)->toEqual(0.00);
    });

    it('leaves shipping alone on an invoice that had none', function () {
        $invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-001-00002',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 13.04,
            'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'reason' => 'إرجاع كامل',
            'refund_shipping' => true,
        ])->assertRedirect();

        // لا شحن على الفاتورة، فلا شيء يُردّ منه مهما طُلب.
        expect((float) Refund::firstOrFail()->shipping_refunded)->toEqual(0.00);
    });
});
