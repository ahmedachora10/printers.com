<?php

use App\Enums\Roles;
use App\Models\AccountReconciliation;
use App\Models\Branch;
use App\Models\CardType;
use App\Models\NetworkDevice;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// تاسك 146 — أجهزة الشبكة وأنواع البطاقات وتقرير مبالغ الشبكة.
describe('Network devices and card types', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
        $this->otherBranch = Branch::factory()->create();
        $this->mada = PaymentMethod::factory()->network()->create(['name' => 'شبكة']);

        $this->makeDevice = fn (Branch $branch, string $number, array $extra = []) => NetworkDevice::create([
            'branch_id' => $branch->id, 'payment_method_id' => $this->mada->id, 'name' => "جهاز $number", 'number' => $number, ...$extra,
        ]);
    });

    it('pins a branch admin\'s device to their branch and keeps one default per branch', function () {
        $old = ($this->makeDevice)($this->branch, '111', ['is_default' => true]);
        $foreign = ($this->makeDevice)($this->otherBranch, '222', ['is_default' => true]);

        $this->actingAs($this->branchAdmin)
            ->post(route('network-devices.store'), [
                'branch_id' => $this->otherBranch->id,
                'payment_method_id' => $this->mada->id,
                'name' => 'جهاز الشبكة 1',
                'number' => '123456',
                'is_default' => true,
            ])
            ->assertSessionHasNoErrors();

        $new = NetworkDevice::firstWhere('number', '123456');
        expect($new->branch_id)->toBe($this->branch->id)
            ->and($new->is_default)->toBeTrue()
            ->and($old->fresh()->is_default)->toBeFalse()
            ->and($foreign->fresh()->is_default)->toBeTrue();
    });

    it('keeps another branch\'s device and the card types away from a branch admin', function () {
        $foreign = ($this->makeDevice)($this->otherBranch, '222');

        $this->actingAs($this->branchAdmin)->delete(route('network-devices.destroy', $foreign))->assertForbidden();
        $this->actingAs($this->branchAdmin)->post(route('card-types.store'), ['name' => 'أمريكان إكسبريس'])->assertForbidden();
    });

    it('lets the super admin add a card type', function () {
        $super = User::factory()->create();
        $super->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($super)->post(route('card-types.store'), ['name' => 'أمريكان إكسبريس'])->assertSessionHasNoErrors();

        expect(CardType::pluck('name')->all())->toBe(['مدى', 'فيزا', 'ماستر كارد', 'أمريكان إكسبريس']);
    });

    it('reports network amounts filtered by device and card type', function () {
        $d1 = ($this->makeDevice)($this->branch, '111');
        $d2 = ($this->makeDevice)($this->branch, '222');
        [$mada, $visa] = CardType::orderBy('id')->get();

        $reconciliation = AccountReconciliation::create([
            'branch_id' => $this->branch->id, 'date' => today()->toDateString(), 'created_by' => $this->branchAdmin->id,
        ]);
        foreach ([[$d1, $mada, 500], [$d1, $visa, 200], [$d2, $mada, 300]] as [$device, $type, $amount]) {
            $reconciliation->devices()->create([
                'payment_method_id' => $this->mada->id, 'network_device_id' => $device->id,
                'card_type_id' => $type->id, 'device_label' => $device->number, 'amount' => $amount,
            ]);
        }

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.network'))
            ->assertInertia(fn ($page) => $page
                ->component('reports/network/index')
                ->where('total', 1000)
                ->where('byCardType.0', ['name' => 'مدى', 'count' => 2, 'total' => 800])
                ->where('byDevice.0.total', 700));

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.network', ['device' => $d1->id, 'card_type' => $mada->id]))
            ->assertInertia(fn ($page) => $page->where('total', 500)->has('rows', 1));

        // خارج المدى: أمس فقط.
        $this->actingAs($this->branchAdmin)
            ->get(route('reports.network', ['from' => today()->subDay()->toDateString(), 'to' => today()->subDay()->toDateString()]))
            ->assertInertia(fn ($page) => $page->where('total', 0)->has('rows', 0));

        $this->actingAs($this->branchAdmin)->get(route('reports.network.export'))->assertOk();
    });
});
