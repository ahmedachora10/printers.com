<?php

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\LoyaltyConfig;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** حمولة فاتورة آجلة بسطرٍ واحد بـ100 ر.س، مع ما يُضاف إليها من توصيل. */
function shippingPayload(array $overrides = []): array
{
    return array_merge([
        'payment_method_id' => paymentMethodId(),
        'status' => 'due',
        'lines' => [[
            'branch_service_id' => test()->service->id,
            'qty' => 1,
            'unit_price' => 100,
            'discount_pct' => 0,
        ]],
    ], $overrides);
}

describe('Shipping fee on the service invoice', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $template = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 50,
            'max_discount_pct' => 50,
            'is_tahazir' => false,
            'is_active' => true,
        ]);
        $this->service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();

        foreach ([$this->employee, $this->branchAdmin] as $raiser) {
            UserService::create([
                'user_id' => $raiser->id,
                'branch_service_id' => $this->service->id,
                'commission_override_pct' => 50,
            ]);
        }

        $this->provider = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id]);
        $this->zone = DeliveryZone::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'حي النرجس',
            'price' => 20,
        ]);

        $this->actingAs($this->employee);
    });

    // ── جوهر البند: العمولة بعد خصم قيمة التوصيل ──────────────────

    it('adds the fee to the total the customer pays', function () {
        $this->post(route('pos.service.store'), shippingPayload([
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
        ]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        // خدماتٌ بـ100 + توصيلٌ بـ20 = 120 يدفعها العميل. والضريبة تُستخرج من
        // داخل الإجمالي كلّه: 120 ÷ 1.15 = 104.35، فالضريبة 15.65.
        expect((float) $invoice->shipping_fee)->toEqual(20.00)
            ->and((float) $invoice->subtotal)->toEqual(100.00)
            ->and((float) $invoice->total_amount)->toEqual(120.00)
            ->and((float) $invoice->vat_amount)->toEqual(15.65);
    });

    it('leaves every commission figure untouched when the fee is raised', function () {
        // نفس الفاتورة مرّتين: بلا توصيل ثم بتوصيلٍ بـ20.
        $this->post(route('pos.service.store'), shippingPayload())->assertRedirect();
        $without = ServiceInvoice::latest('id')->firstOrFail();

        $this->post(route('pos.service.store'), shippingPayload([
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
        ]))->assertRedirect();
        $with = ServiceInvoice::latest('id')->firstOrFail();

        // هذا نصّ العميل بالأحمر: «احتساب العمولات بعد خصم قيمة التوصيل».
        // 100 ÷ 1.15 = 86.96 × 50% = 43.48 — في الحالتين سواء.
        expect((float) $with->employee_commission)->toEqual(43.48)
            ->and((float) $with->employee_commission)->toEqual((float) $without->employee_commission)
            ->and((float) $with->lines()->firstOrFail()->commission_amount)
            ->toEqual((float) $without->lines()->firstOrFail()->commission_amount);

        // ويرتفع الإجمالي وحده بعشرين بالضبط.
        expect((float) $with->total_amount - (float) $without->total_amount)->toEqual(20.00);
    });

    it('keeps the agent rebate off the shipping money', function () {
        $agentUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $agentUser->addRole(Roles::AGENT->value);
        $agentUser->agentProfile()->create([
            'agent_type' => 'individual',
            'discount_mode' => AgentDiscountModeEnum::Rebate,
            'discount_type' => AgentDiscountTypeEnum::Percentage,
            'rate' => 10,
            'is_active' => true,
        ]);
        $agentUser->refresh();
        setAgentBranchTerms($agentUser, $this->branch->id, ['rate' => 10]);

        $this->post(route('pos.service.store'), shippingPayload([
            'agent_ids' => [$agentUser->id],
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
        ]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        // الريبيت 10% من صافي الخدمات (86.96) = 8.70 — لا من 104.35.
        // ريبيتٌ على أجرة السائق ليس عمولةَ مندوب.
        expect((float) $invoice->invoiceAgents()->firstOrFail()->rebate_amount)->toEqual(8.70);
    });

    it('earns loyalty points on the services alone', function () {
        LoyaltyConfig::updateOrCreate(
            ['branch_id' => $this->branch->id],
            ['earning_rate' => 1, 'is_active' => true, 'bronze_threshold' => 100000],
        );

        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'agent_id' => null,
            'points_balance' => 0,
            'cumulative_spend' => 0,
            'tier' => CustomerTierEnum::None,
        ]);

        // الموظف لا ينشئ إلا فاتورةً آجلة، والنقاط لا تُكتسب إلا على مدفوعة —
        // فيرفعها المراجع مباشرةً مسدَّدة.
        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), shippingPayload([
                'status' => 'paid',
                'customer_id' => $customer->id,
                'shipping_provider_id' => $this->provider->id,
                'shipping_zone_id' => $this->zone->id,
            ]))->assertRedirect();

        $customer->refresh();

        // النقاط على صافي الخدمات: floor(86.96) = 86، لا floor(104.35) = 104.
        // والإنفاق التراكمي على الخدمات شاملةً ضريبتها: 100 لا 120.
        expect($customer->points_balance)->toBe(86)
            ->and((float) $customer->cumulative_spend)->toEqual(100.00);
    });

    // ── من يملك تعديل القيمة ──────────────────────────────────────

    it('ignores a fee an employee types and uses the zone price', function () {
        $this->post(route('pos.service.store'), shippingPayload([
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
            'shipping_fee' => 5,
        ]))->assertRedirect();

        // سعر الشريحة هو الحاكم: الموظف لا يُخفّض على العميل شيئاً.
        expect((float) ServiceInvoice::firstOrFail()->shipping_fee)->toEqual(20.00);
    });

    it('honours a fee a reviewer types', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), shippingPayload([
                'shipping_provider_id' => $this->provider->id,
                'shipping_zone_id' => $this->zone->id,
                'shipping_fee' => 35,
            ]))->assertRedirect();

        expect((float) ServiceInvoice::firstOrFail()->shipping_fee)->toEqual(35.00);
    });

    // ── التوصيل المجّاني ──────────────────────────────────────────

    it('records a free delivery with the driver still named', function () {
        $free = DeliveryZone::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'توصيل مجاني',
            'price' => 0,
        ]);

        $this->post(route('pos.service.store'), shippingPayload([
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $free->id,
        ]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        expect((float) $invoice->shipping_fee)->toEqual(0.00)
            ->and($invoice->shipping_provider_id)->toBe($this->provider->id)
            ->and((float) $invoice->total_amount)->toEqual(100.00);
    });

    it('writes no shipping at all when neither provider nor zone is sent', function () {
        $this->post(route('pos.service.store'), shippingPayload())->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        expect((float) $invoice->shipping_fee)->toEqual(0.00)
            ->and($invoice->shipping_provider_id)->toBeNull()
            ->and((float) $invoice->total_amount)->toEqual(100.00);
    });

    // ── حدود الفرع ────────────────────────────────────────────────

    it('refuses a provider from another branch', function () {
        $foreign = DeliveryProvider::factory()->create();

        $this->post(route('pos.service.store'), shippingPayload([
            'shipping_provider_id' => $foreign->id,
            'shipping_zone_id' => $this->zone->id,
        ]))->assertSessionHasErrors('shipping_provider_id');
    });

    it('refuses an inactive zone', function () {
        $this->zone->update(['is_active' => false]);

        $this->post(route('pos.service.store'), shippingPayload([
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
        ]))->assertSessionHasErrors('shipping_zone_id');
    });

    // ── دفتر العناوين ─────────────────────────────────────────────

    it('adds a new address to the customer book without wiping the old one', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $home = CustomerAddress::create([
            'customer_id' => $customer->id,
            'label' => 'المنزل',
            'address' => 'حي الياسمين، شارع 5',
            'is_default' => true,
        ]);

        $this->post(route('pos.service.store'), shippingPayload([
            'customer_id' => $customer->id,
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
            'shipping_address' => 'حي النرجس، مكتب 12',
            'save_shipping_address' => true,
            'shipping_address_label' => 'المكتب',
        ]))->assertRedirect();

        $customer->refresh();

        // عنوانان لا واحد، والافتراضيّ لم يتغيّر.
        expect($customer->addresses)->toHaveCount(2)
            ->and($home->refresh()->is_default)->toBeTrue()
            ->and($customer->addresses->firstWhere('label', 'المكتب')->is_default)->toBeFalse();
    });

    it('does not duplicate an address already in the book', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'address' => 'حي النرجس، مكتب 12',
            'is_default' => true,
        ]);

        $this->post(route('pos.service.store'), shippingPayload([
            'customer_id' => $customer->id,
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
            'shipping_address' => 'حي النرجس، مكتب 12',
            'save_shipping_address' => true,
        ]))->assertRedirect();

        expect($customer->refresh()->addresses)->toHaveCount(1);
    });

    it('keeps the printed address as a snapshot when the book is edited later', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $address = CustomerAddress::create([
            'customer_id' => $customer->id,
            'address' => 'حي النرجس، مكتب 12',
            'delivery_zone_id' => $this->zone->id,
            'is_default' => true,
        ]);

        $this->post(route('pos.service.store'), shippingPayload([
            'customer_id' => $customer->id,
            'customer_address_id' => $address->id,
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
        ]))->assertRedirect();

        $address->update(['address' => 'عنوان آخر تماماً']);

        expect(ServiceInvoice::firstOrFail()->shipping_address)->toBe('حي النرجس، مكتب 12');
    });

    it('refuses an address belonging to another customer', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $other = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $foreign = CustomerAddress::create([
            'customer_id' => $other->id,
            'address' => 'عنوان غريب',
        ]);

        $this->post(route('pos.service.store'), shippingPayload([
            'customer_id' => $customer->id,
            'customer_address_id' => $foreign->id,
            'shipping_provider_id' => $this->provider->id,
            'shipping_zone_id' => $this->zone->id,
        ]))->assertSessionHasErrors('customer_address_id');
    });
});
