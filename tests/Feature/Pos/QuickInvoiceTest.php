<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserFavoriteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function quickService(Branch $branch, string $name, int $sort, array $attributes = []): BranchService
{
    $template = ServiceTemplate::factory()->create(['name' => $name, 'sort_order' => $sort]);

    BranchService::create([
        'branch_id' => $branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 0,
        'is_active' => true,
        ...$attributes,
    ]);

    return BranchService::where('branch_id', $branch->id)->where('service_template_id', $template->id)->firstOrFail();
}

/**
 * تاسك 118: الفاتورة السريعة — مبلغ ← Enter ← فاتورة معلّقة ← الشاشة نفسها.
 */
describe('Quick invoice', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('saves a due invoice and stays on the quick screen with the id to print', function () {
        $service = quickService($this->branch, 'تصوير', 1);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'quick' => true,
                'print' => true,
                'lines' => [['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 23, 'discount_pct' => 0]],
            ])
            ->assertRedirect(route('pos.service.quick'));

        $invoice = ServiceInvoice::sole();
        expect($invoice->status)->toBe(InvoiceStatusEnum::DUE)
            ->and((float) $invoice->total_amount)->toBe(23.0);

        $this->get(route('pos.service.quick'))
            ->assertInertia(fn ($page) => $page->component('pos/quick')->where('printInvoiceId', $invoice->id));
    });

    it('orders the default, then the rest — piece-priced only, eight at most', function () {
        $services = collect(range(1, 9))->map(fn ($i) => quickService($this->branch, "خدمة $i", $i));
        quickService($this->branch, 'لوحة بالمتر', 0, ['pricing_type' => 'sqm', 'price_per_sqm' => 50]);

        $this->actingAs($this->employee)
            ->post(route('pos.service.quick.default', $services[6]->id))
            ->assertRedirect();

        $this->get(route('pos.service.quick'))
            ->assertInertia(fn ($page) => $page
                ->where('defaultServiceId', $services[6]->id)
                ->where('services', fn ($list) => $list->pluck('id')->all() === [
                    $services[6]->id,
                    $services[0]->id, $services[1]->id, $services[2]->id, $services[3]->id, $services[4]->id, $services[5]->id, $services[7]->id,
                ])
                // المسعّرة بالمتر تظهر في القائمة آخراً، معطّلةً بسببها.
                ->has('eligibleServices', 10)
                ->where('eligibleServices.0.disabledReason', null)
                ->where('eligibleServices.9.name', 'لوحة بالمتر')
                ->where('eligibleServices.9.disabledReason', fn ($r) => str_contains($r, 'بالمتر')));

        // الضغطة الثانية تُلغي الافتراضية.
        $this->post(route('pos.service.quick.default', $services[6]->id));
        expect($this->employee->fresh()->quick_service_id)->toBeNull();
    });

    it('shows only the default and the chosen favourites, twelve at most', function () {
        $services = collect(range(1, 15))->map(fn ($i) => quickService($this->branch, "خدمة $i", $i));
        $this->employee->forceFill(['quick_service_id' => $services[14]->id])->save();

        $this->actingAs($this->employee)->post(route('pos.service.favorites.toggle', $services[3]->id));

        $this->get(route('pos.service.quick'))
            ->assertInertia(fn ($page) => $page
                ->where('services', fn ($list) => $list->pluck('id')->all() === [$services[14]->id, $services[3]->id])
                ->where('eligibleServices.3.isFavorite', true));

        $services->take(13)->each(fn ($s) => UserFavoriteService::firstOrCreate(['user_id' => $this->employee->id, 'branch_service_id' => $s->id]));

        $this->get(route('pos.service.quick'))
            ->assertInertia(fn ($page) => $page->where('services', fn ($list) => $list->count() === 12 && $list->first()['id'] === $services[14]->id));
    });

    it('is the employee screen only', function () {
        $admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->actingAs($admin)->get(route('pos.service.quick'))->assertForbidden();
    });
});
