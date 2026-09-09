<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** فاتورة خدمات مسدَّدة: خدماتٌ بـ100 + توصيلٌ بـ20 = 120. */
function shippedSale(Branch $branch, User $user, float $shipping = 20): ServiceInvoice
{
    $provider = DeliveryProvider::factory()->create(['branch_id' => $branch->id]);

    return ServiceInvoice::create([
        'invoice_number' => 'SINV-001-'.fake()->unique()->numerify('#####'),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'shipping_fee' => $shipping,
        'shipping_provider_id' => $provider->id,
        'vat_pct' => 15,
        'vat_amount' => 15.65,
        'total_amount' => round(100 + $shipping, 2),
        'employee_commission' => 0,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
    ])->fresh();
}

describe('Shipping stands apart in the reports', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id, 'vat_rate_override' => 15]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->admin);
    });

    // ── تقرير المبيعات ────────────────────────────────────────────

    it('reports the shipping apart from the revenue', function () {
        shippedSale($this->branch, $this->admin);

        $this->get(route('reports.sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // الإجمالي ما دفعه العميل، والشحن منه مبيَّنٌ على حدة.
                ->where('totals.total', 120)
                ->where('totals.shipping', 20));
    });

    it('keeps the union intact when a product invoice joins in', function () {
        shippedSale($this->branch, $this->admin);

        // ⚠️ التقرير اتحادٌ بين جدولَي الفواتير، وجدول المنتجات بلا عمود شحن:
        // بغير `0` صريحة على فرعه ينكسر الاتحاد بخطأ SQL لا برقمٍ خاطئ.
        ProductInvoice::create([
            'invoice_number' => 'INV-001-00001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 50,
            'vat_pct' => 15,
            'vat_amount' => 6.52,
            'total_amount' => 50,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);

        $this->get(route('reports.sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.total', 170)
                ->where('totals.shipping', 20)
                // وصفّ المنتجات يقرأ شحناً صفراً لا خطأً.
                ->where('byType.0.shipping', 0)
                ->where('byType.1.shipping', 20));
    });

    it('reports no shipping when nothing was delivered', function () {
        shippedSale($this->branch, $this->admin, 0);

        $this->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.shipping', 0));
    });

    it('drops the shipping the accountant chose to return', function () {
        $invoice = shippedSale($this->branch, $this->admin);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 120,
            'reason' => 'الطلب لم يُشحن',
            'refund_shipping' => true,
        ])->assertRedirect();

        // رُدّ الشحن كاملاً فسقطت جملته؛ ولو لم يُردّ لبقيت 20 (الاختبار التالي).
        $this->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.shipping', 0));
    });

    it('keeps the shipping the accountant chose not to return', function () {
        $invoice = shippedSale($this->branch, $this->admin);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'reason' => 'مرتجع بضاعة — السائق ذهب فعلاً',
        ])->assertRedirect();

        // مرتجعٌ جزئي عمداً: المرتجع الكامل يقلب الفاتورة إلى «مرتجعة» فتسقط من
        // التقرير كلّها. والمرتجع لا يُنقص جملة الشحن ما لم يقرّر المحاسب ردّه —
        // القرار مكتوبٌ على صفّ المرتجع، فلا يُستنتج نسبياً من المبلغ.
        $this->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.shipping', 20));
    });

    // ── التقرير اليومي ────────────────────────────────────────────

    it('keeps the shipping out of the daily services column', function () {
        shippedSale($this->branch, $this->admin);

        $this->get(route('reports.daily'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // الخدمات 100 لا 120، والشحن في عموده، والإجمالي يجمعهما —
                // فيبقى مطابقاً لما دفعه العميل ولعمود «المحصَّل».
                ->where('totals.services', 100)
                ->where('totals.shipping', 20)
                ->where('totals.total', 120));
    });
});
